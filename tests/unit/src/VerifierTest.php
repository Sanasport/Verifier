<?php

namespace Tests\Unit;

use Arachne\DIHelpers\ResolverInterface;
use Arachne\Verifier\Exception\VerificationException;
use Arachne\Verifier\RuleHandlerInterface;
use Arachne\Verifier\RuleProviderInterface;
use Arachne\Verifier\Verifier;
use Codeception\MockeryModule\Test;
use Mockery;
use Mockery\Matcher\MatcherAbstract;
use Mockery\MockInterface;
use Nette\Application\IPresenterFactory;
use Nette\Application\Request;
use Nette\Application\UI\Presenter;
use Nette\Application\UI\PresenterComponent;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Reflector;
use Tests\Unit\Classes\InvalidRule;
use Tests\Unit\Classes\TestControl;
use Tests\Unit\Classes\TestPresenter;
use Tests\Unit\Classes\TestRule;

/**
 * @author Jáchym Toušek <enumag@gmail.com>
 */
class VerifierTest extends Test
{

	/** @var Verifier */
	private $verifier;

	/** @var MockInterface */
	private $ruleProvider;

	/** @var MockInterface */
	private $handlerResolver;

	/** @var MockInterface */
	private $presenterFactory;

	protected function _before()
	{
		$this->ruleProvider = Mockery::mock(RuleProviderInterface::class);
		$this->handlerResolver = Mockery::mock(ResolverInterface::class);
		$this->presenterFactory = Mockery::mock(IPresenterFactory::class);
		$this->verifier = new Verifier($this->ruleProvider, $this->handlerResolver, $this->presenterFactory);
	}

	public function testGetRulesOnClass()
	{
		$reflection = $this->createClassReflection();
		$this->setupRuleProviderMock($reflection);

		$this->assertEquals([ new TestRule() ], $this->verifier->getRules($reflection));
	}

	public function testGetRulesOnMethod()
	{
		$reflection = $this->createMethodReflection();
		$this->setupRuleProviderMock($reflection);

		$this->assertEquals([ new TestRule() ], $this->verifier->getRules($reflection));
	}

	/**
	 * @expectedException Arachne\Verifier\Exception\InvalidArgumentException
	 * @expectedExceptionMessage Reflection must be an instance of either ReflectionMethod, ReflectionClass or ReflectionProperty.
	 */
	public function testGetRulesOnReflector()
	{
		$reflection = Mockery::mock(Reflector::class);
		$this->verifier->getRules($reflection);
	}

	public function testCheckRules()
	{
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, 2);

		$this->setupHandlerResolverMock($handler, 2);

		$this->verifier->checkRules([ new TestRule(), new TestRule() ], $request);
	}

	public function testCheckReflectionOnClass()
	{
		$reflection = $this->createClassReflection(2);
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, 2);

		$this->setupRuleProviderMock($reflection);
		$this->setupHandlerResolverMock($handler, 2);

		$this->verifier->checkReflection($reflection, $request);
		$this->verifier->checkReflection($reflection, $request);
	}

	public function testCheckReflectionOnMethod()
	{
		$reflection = $this->createMethodReflection(2);
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, 2);

		$this->setupRuleProviderMock($reflection);
		$this->setupHandlerResolverMock($handler, 2);

		$this->verifier->checkReflection($reflection, $request);
		$this->verifier->checkReflection($reflection, $request);
	}

	public function testIsLinkVerifiedTrue()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'action',
		]);
		$handler = $this->createHandlerMock($request, 2);

		$this->setupRuleProviderMock(Mockery::type(ReflectionMethod::class));
		$this->setupRuleProviderMock(Mockery::type(ReflectionClass::class));
		$this->setupHandlerResolverMock($handler, 2);
		$this->setupPresenterFactoryMock();

		$this->assertTrue($this->verifier->isLinkVerified($request, Mockery::mock(PresenterComponent::class)));
	}

	public function testIsLinkVerifiedFalse()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'view',
		]);
		$handler = $this->createHandlerMock($request, 1, null, true);

		$this->setupRuleProviderMock(Mockery::type(ReflectionClass::class));
		$this->setupHandlerResolverMock($handler);
		$this->setupPresenterFactoryMock();

		$this->assertFalse($this->verifier->isLinkVerified($request, Mockery::mock(PresenterComponent::class)));
	}

	public function testIsLinkVerifiedSignal()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'action',
			Presenter::SIGNAL_KEY => 'signal',
		]);
		$handler = $this->createHandlerMock($request);

		$this->setupRuleProviderMock(Mockery::type(ReflectionMethod::class));
		$this->setupHandlerResolverMock($handler);
		$this->setupPresenterFactoryMock();

		$component = Mockery::mock(PresenterComponent::class);

		$this->assertTrue($this->verifier->isLinkVerified($request, $component));
	}

	public function testIsComponentVerifiedTrue()
	{
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request);

		$this->setupRuleProviderMock(Mockery::type(ReflectionMethod::class));
		$this->setupHandlerResolverMock($handler);

		$parent = new TestPresenter();
		$parent->setParent(null, 'Test');

		$this->assertTrue($this->verifier->isComponentVerified('component', $request, $parent));
	}

	public function testIsComponentVerifiedFalse()
	{
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, 1, null, true);

		$this->setupRuleProviderMock(Mockery::type(ReflectionMethod::class));
		$this->setupHandlerResolverMock($handler);

		$parent = new TestPresenter();

		$this->assertFalse($this->verifier->isComponentVerified('component', $request, $parent));
	}

	public function testIsComponentSignalVerifiedTrue()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'action',
			Presenter::SIGNAL_KEY => 'component-signal',
		], false);
		$handler = $this->createHandlerMock($request, 1, 'component');

		$this->setupRuleProviderMock(Mockery::type(ReflectionMethod::class));
		$this->setupHandlerResolverMock($handler);

		$component = new TestControl(null, 'component');
		$parent = Mockery::mock(Presenter::class)
			->shouldDeferMissing();
		$component->setParent($parent);

		$this->assertTrue($this->verifier->isLinkVerified($request, $component));
	}

	/**
	 * @expectedException Arachne\Verifier\Exception\InvalidArgumentException
	 * @expectedExceptionMessage Wrong signal receiver, expected 'component' component but 'test-component' was given.
	 */
	public function testWrongSignalReceiver()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'action',
			Presenter::SIGNAL_KEY => 'component-signal',
		], false);

		$component = Mockery::mock(PresenterComponent::class)
			->shouldReceive('getUniqueId')
			->once()
			->andReturn('test-component')
			->getMock();

		$this->verifier->isLinkVerified($request, $component);
	}

	/**
	 * @expectedException Arachne\Verifier\Exception\UnexpectedTypeException
	 */
	public function testInvalidRule()
	{
		$request = $this->createRequestMock([
			Presenter::ACTION_KEY => 'invalid',
		], true);

		$this->setupPresenterFactoryMock();
		$this->ruleProvider
			->shouldReceive('getRules')
			->times(1)
			->with(Mockery::type(ReflectionClass::class))
			->andReturn([ new InvalidRule() ]);
		$this->handlerResolver
			->shouldReceive('resolve')
			->with(InvalidRule::class)
			->times(1)
			->andReturn();

		$component = Mockery::mock(PresenterComponent::class);

		$this->verifier->isLinkVerified($request, $component);
	}

	public function testVerifyPropertiesTrue()
	{
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, null);

		$this->setupRuleProviderMock(Mockery::type(ReflectionProperty::class), null);
		$this->setupHandlerResolverMock($handler, null);

		$parent = new TestPresenter();
		$parent->setParent(null, 'Test');

		$this->verifier->verifyProperties($request, $parent);
		$this->assertTrue($parent->property);
	}

	public function testVerifyPropertiesFalse()
	{
		$request = Mockery::mock(Request::class);
		$handler = $this->createHandlerMock($request, null, null, true);

		$this->setupRuleProviderMock(Mockery::type(ReflectionProperty::class), null);
		$this->setupHandlerResolverMock($handler, null);

		$parent = new TestPresenter();
		$parent->setParent(null, 'Test');

		$this->verifier->verifyProperties($request, $parent);
		$this->assertFalse($parent->property);
	}

	/**
	 * @param int $limit
	 * @return ReflectionClass
	 */
	private function createClassReflection($limit = 1)
	{
		return Mockery::mock(ReflectionClass::class)
			->shouldReceive('getName')
			->times($limit)
			->andReturn('class')
			->getMock();
	}

	/**
	 * @param int $limit
	 * @return ReflectionMethod
	 */
	private function createMethodReflection($limit = 1)
	{
		return Mockery::mock(ReflectionMethod::class)
			->shouldReceive('getName')
			->times($limit)
			->andReturn('method')
			->shouldReceive('getDeclaringClass')
			->times($limit)
			->andReturn($this->createClassReflection($limit))
			->getMock();
	}

	/**
	 * @param Request $request
	 * @param int $limit
	 * @param string $component
	 * @return RuleHandlerInterface
	 */
	private function createHandlerMock(Request $request, $limit = 1, $component = null, $throw = false)
	{
		$mock = Mockery::mock(RuleHandlerInterface::class);
		$method = $mock->shouldReceive('checkRule')
			->times($limit)
			->with(Mockery::type(TestRule::class), $request, $component);
		if ($throw) {
			$method->andThrow(Mockery::mock(VerificationException::class));
		} else {
			$method->andReturnNull();
		}
		return $mock;
	}

	/**
	 * @param array $parameters
	 * @param bool $presenter
	 * @return Request
	 */
	private function createRequestMock(array $parameters, $presenter = true)
	{
		$request = Mockery::mock(Request::class);
		$request->shouldReceive('getParameters')
			->once()
			->andReturn($parameters);
		if ($presenter) {
			$request->shouldReceive('getPresenterName')
				->once()
				->andReturn('Test');
		}
		return $request;
	}

	/**
	 * @param RuleHandlerInterface $handler
	 * @param int $limit
	 */
	private function setupHandlerResolverMock(RuleHandlerInterface $handler, $limit = 1)
	{
		$this->handlerResolver
			->shouldReceive('resolve')
			->with(TestRule::class)
			->times($limit)
			->andReturn($handler);
	}

	/**
	 * @param Reflector|MatcherAbstract $matcher
	 * @param int $limit
	 */
	private function setupRuleProviderMock($matcher, $limit = 1)
	{
		$this->ruleProvider
			->shouldReceive('getRules')
			->times($limit)
			->with($matcher)
			->andReturn([ new TestRule() ]);
	}

	private function setupPresenterFactoryMock()
	{
		$this->presenterFactory
			->shouldReceive('getPresenterClass')
			->with('Test')
			->once()
			->andReturn(TestPresenter::class);
	}

}
