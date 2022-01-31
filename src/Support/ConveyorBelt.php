<?php

namespace Glhd\ConveyorBelt\Support;

use Closure;
use Glhd\ConveyorBelt\Exceptions\AbortConveyorBeltException;
use Illuminate\Console\Concerns\InteractsWithIO;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SqlFormatter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Throwable;

class ConveyorBelt
{
	use InteractsWithIO {
		table as defaultTable;
	}
	
	public ProgressBar $progress;
	
	/** @var \Glhd\ConveyorBelt\IteratesQuery|\Illuminate\Console\Command */
	protected $command;
	
	/** @var BaseBuilder|EloquentBuilder|Relation */
	protected $query = null;
	
	/** @var \Glhd\ConveyorBelt\Support\CollectedException[] */
	protected array $exceptions = [];
	
	public function __construct($command)
	{
		$this->command = $command;
		
		$this->addConveyorBeltOptions($command->getDefinition());
	}
	
	public function initialize(InputInterface $input, OutputStyle $output): void
	{
		$this->input = $input;
		$this->output = $output;
		
		$this->progress = new ProgressBar($input, $output);
	}
	
	public function run(): int
	{
		$this->newLine();
		
		try {
			$this->prepare();
			$this->printIntro();
			$this->start();
			$this->finish();
			
			return Command::SUCCESS;
		} catch (AbortConveyorBeltException $exception) {
			if (! empty($message = $exception->getMessage())) {
				$this->error($message);
			}
			
			return $exception->getCode();
		} finally {
			$this->newLine();
		}
	}
	
	protected function prepare(): void
	{
		$this->verifyCommandSetup();
		$this->prepareForQueryLogging();
		$this->setVerbosityBasedOnStepMode();
		
		// The "before first row" hook should run before the --dump-sql flag
		// is checked, just in case the command needs to set up any data that
		// will be used to build the query (i.e. other inputs or environmental data)
		$this->command->beforeFirstRow();
		
		// Once everything else is prepared, we'll check for the --dump-sql
		// flag and if it's set, print the query and exit
		$this->dumpSqlAndAbortIfRequested();
	}
	
	protected function start(): void
	{
		if (! $count = $this->query()->count()) {
			$this->command->info(trans('conveyor-belt::messages.no_matches', ['records' => $this->command->rowNamePlural()]));
			return;
		}
		
		$this->progress->start($count, $this->command->rowName(), $this->command->rowNamePlural());
		
		if ($this->command->useTransaction()) {
			DB::transaction(fn() => $this->executeQuery());
		} else {
			$this->executeQuery();
		}
		
		$this->progress->finish();
	}
	
	protected function finish(): void
	{
		$this->command->afterLastRow();
		
		$this->showCollectedExceptions();
	}
	
	protected function abort(string $message = '', int $code = Command::FAILURE): void
	{
		throw new AbortConveyorBeltException($message, $code);
	}
	
	protected function executeQuery(): void
	{
		$this->command->beforeFirstQuery();
		
		$this->command->iterateOverQuery($this->query(), $this->getChunkHandler());
	}
	
	protected function getChunkHandler(): Closure
	{
		return function($items) {
			$this->command->prepareChunk($items);
			
			foreach ($items as $item) {
				if (false === $this->presentRow($item)) {
					return false;
				}
			}
			
			return true;
		};
	}
	
	protected function presentRow($item): bool
	{
		try {
			$original = $this->getOriginalForDiff($item);
			$this->command->handleRow($item);
		} catch (Throwable $throwable) {
			$this->handleRowException($throwable, $item);
		}
		
		$this->progress->advance();
		
		$this->logSql();
		$this->logDiff($item, $original);
		$this->pauseIfStepping();
		
		return true;
	}
	
	protected function handleRowException(Throwable $exception, $item): void
	{
		if ($this->shouldThrowRowException()) {
			$this->progress->finish();
			throw $exception;
		}
		
		$this->printError($exception);
		$this->pauseOnErrorIfRequested();
		
		if ($this->command->collectExceptions()) {
			$this->exceptions[] = new CollectedException($exception, $item);
		}
	}
	
	protected function shouldThrowRowException(): bool
	{
		return ! $this->command->collectExceptions()
			&& ! $this->option('pause-on-error');
	}
	
	protected function printError(Throwable $exception): void
	{
		if ($this->output->isVerbose()) {
			$this->progress->interrupt(fn() => $this->error($exception));
			return;
		}
		
		if ($this->option('pause-on-error')) {
			$this->progress->interrupt(fn() => $this->error(get_class($exception).': '.$exception->getMessage()));
		}
	}
	
	protected function pauseOnErrorIfRequested(): void
	{
		if (! $this->option('pause-on-error')) {
			return;
		}
		
		$this->progress->pause();
		
		if (! $this->confirm(trans('conveyor-belt::messages.confirm_continue'))) {
			$this->progress->finish();
			$this->abort(trans('conveyor-belt::messages.operation_cancelled'));
		}
		
		$this->progress->resume();
	}
	
	protected function logSql(): void
	{
		if (! $this->option('log-sql')) {
			return;
		}
		
		$table = collect(DB::getQueryLog())
			->map(fn($log) => [$this->getFormattedQuery($log['query'], $log['bindings']), $log['time']]);
		
		if ($table->isEmpty()) {
			return;
		}
		
		$this->newLine();
		$this->line(trans_choice('conveyor-belt::messages.queries_executed', $table->count()));
		$this->table([trans('conveyor-belt::messages.query_heading'), trans('conveyor-belt::messages.time_heading')], $table);
		
		DB::flushQueryLog();
	}
	
	protected function getOriginalForDiff($item): array
	{
		if (! $item instanceof Model || ! $this->option('diff')) {
			return [];
		}
		
		return $item->getOriginal();
	}
	
	protected function logDiff($item, array $original): void
	{
		if (! $this->option('diff')) {
			return;
		}
		
		if (! $item instanceof Model) {
			$this->abort('The --diff flag requires Eloquent models');
		}
		
		if (empty($changes = $item->getChanges())) {
			return;
		}
		
		$table = collect($changes)->map(fn($value, $key) => ["<info>{$key}</info>", $original[$key] ?? null, $value]);
		
		$this->progress->pause();
		
		$this->newLine();
		
		$this->line(trans('conveyor-belt::messages.changes_to_record', ['record' => $this->command->rowName()]));
		$this->table([trans('conveyor-belt::messages.before_heading'), trans('conveyor-belt::messages.after_heading')], $table);
		
		$this->progress->resume();
	}
	
	protected function pauseIfStepping(): void
	{
		if ($this->option('step') && ! $this->confirm(trans('conveyor-belt::messages.confirm_continue'), true)) {
			$this->abort(trans('conveyor-belt::messages.operation_cancelled'));
		}
	}
	
	protected function verifyCommandSetup(): void
	{
		if (! method_exists($this->command, 'handleRow')) {
			$this->abort('You must implement '.class_basename($this->command).'::handleRow()', Command::INVALID);
		}
	}
	
	protected function prepareForQueryLogging(): void
	{
		if ($this->option('log-sql')) {
			$this->input->setOption('step', true);
			DB::enableQueryLog();
		}
	}
	
	protected function setVerbosityBasedOnStepMode(): void
	{
		if ($this->option('step')) {
			$this->output->setVerbosity(OutputInterface::VERBOSITY_VERBOSE);
		}
	}
	
	protected function dumpSqlAndAbortIfRequested(): void
	{
		if (! $this->option('dump-sql')) {
			return;
		}
		
		$query = $this->query();
		$this->printFormattedQuery($query->toSql(), $query->getBindings());
		
		$this->abort();
	}
	
	protected function printFormattedQuery(string $sql, array $bindings): void
	{
		$this->newLine();
		
		$this->line($this->getFormattedQuery($sql, $bindings));
	}
	
	protected function getFormattedQuery(string $sql, array $bindings): string
	{
		$bindings = Arr::flatten($bindings);
		
		$sql = preg_replace_callback('/\?/', static function() use (&$bindings) {
			return DB::getPdo()->quote(array_shift($bindings));
		}, $sql);
		
		return SqlFormatter::format($sql);
	}
	
	protected function printIntro(): void
	{
		$message = $this->command->useTransaction()
			? trans('conveyor-belt::messages.querying_with_transaction', ['records' => $this->command->rowNamePlural()])
			: trans('conveyor-belt::messages.querying_without_transaction', ['records' => $this->command->rowNamePlural()]);
		
		$this->info($message);
	}
	
	protected function showCollectedExceptions(): void
	{
		if (! $count = count($this->exceptions)) {
			return;
		}
		
		$this->newLine();
		
		$this->error(trans_choice('conveyor-belt::messages.exceptions_triggered', $count));
		
		$headers = [
			Str::title($this->command->rowName()),
			trans('conveyor-belt::messages.exception_heading'),
			trans('conveyor-belt::messages.message_heading'),
		];
		
		$rows = collect($this->exceptions)
			->map(fn(CollectedException $exception) => [$exception->key, get_class($exception->exception), (string) $exception]);
		
		$this->table($headers, $rows);
		
		$this->abort();
	}
	
	public function table($headers, $rows, $tableStyle = 'box', array $columnStyles = [])
	{
		$this->defaultTable($headers, $rows, $tableStyle, $columnStyles);
	}
	
	protected function addConveyorBeltOptions(InputDefinition $definition): void
	{
		$definition->addOption(new InputOption('dump-sql', null, null, 'Dump the SQL of the query this command will execute'));
		$definition->addOption(new InputOption('log-sql', null, null, 'Log all SQL queries executed and print them'));
		$definition->addOption(new InputOption('step', null, null, "Step through each {$this->command->rowName()} one-by-one"));
		$definition->addOption(new InputOption('diff', null, null, 'See a diff of any changes made to your models'));
		$definition->addOption(new InputOption('show-memory-usage', null, null, 'Include the command’s memory usage in the progress bar'));
		$definition->addOption(new InputOption('pause-on-error', null, null, 'Pause if an exception is thrown'));
	}
	
	/**
	 * @return BaseBuilder|EloquentBuilder|Relation
	 */
	protected function query()
	{
		return $this->query ??= $this->fetchQueryFromCommand();
	}
	
	protected function fetchQueryFromCommand()
	{
		if (! method_exists($this->command, 'query')) {
			$this->abort('You must implement '.class_basename($this->command).'::query()', Command::INVALID);
		}
		
		$query = $this->command->query();
		
		$expected = [
			BaseBuilder::class,
			EloquentBuilder::class,
			Relation::class,
		];
		
		foreach ($expected as $name) {
			if ($query instanceof $name) {
				return $query;
			}
		}
		
		$this->abort(class_basename($this->command).'::query() must return a query builder', Command::INVALID);
	}
}
