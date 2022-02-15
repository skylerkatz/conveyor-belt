<?php

namespace Glhd\ConveyorBelt\Tests;

use Illuminate\Foundation\Application;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Testing\PendingCommand;
use RuntimeException;

/** @mixin PendingCommand */
class PendingConveyorBeltCommand
{
	use ForwardsCalls;
	
	protected TestCase $test;
	
	protected Application $app;
	
	protected string $command;
	
	protected array $parameters = [
		'--step' => false,
		'--throw' => false,
	];
	
	protected ?PendingCommand $pending = null;
	
	protected int $expected_exit_code = 0;
	
	public function __construct(TestCase $test, Application $app, string $command, array $parameters = [])
	{
		$this->test = $test;
		$this->app = $app;
		$this->command = $command;
		$this->parameters = array_merge($this->parameters, $parameters);
	}
	
	public function command(): PendingCommand
	{
		if (null === $this->pending) {
			$this->pending = new PendingCommand($this->test, $this->app, $this->command, $this->parameters);
			
			$this->setStepExpectations($this->pending);
			
			$this->pending->assertExitCode($this->expected_exit_code);
		}
		
		return $this->pending;
	}
	
	public function throwingExceptions(bool $throw): self
	{
		if ($throw) {
			$this->parameters['--throw'] = true;
			$this->test->expectException(RuntimeException::class);
		}
		
		return $this;
	}
	
	public function expectingSuccessfulReturnCode(bool $succeed): self
	{
		$this->expected_exit_code = $succeed
			? 0
			: 1;
		
		return $this;
	}
	
	public function withStepMode(bool $step): self
	{
		if ($step) {
			$this->parameters['--step'] = true;
		}
		
		return $this;
	}
	
	protected function setStepExpectations(PendingCommand $command)
	{
		if ($this->parameters['--step']) {
			$command->expectsQuestion('Continue?', true);
			
			if (! $this->parameters['--throw']) {
				$command->expectsQuestion('Continue?', true);
				$command->expectsQuestion('Continue?', true);
				$command->expectsQuestion('Continue?', true);
			}
		}
	}
	
	public function __call($name, $arguments)
	{
		return $this->forwardDecoratedCallTo($this->command(), $name, $arguments);
	}
}
