<?php

declare(strict_types=1);

namespace Arachne\Verifier\Application;

use Arachne\Verifier\Exception\NotSupportedException;
use Arachne\Verifier\Verifier;
use Nette\Application\BadRequestException;
use Nette\Application\Request;
use ReflectionClass;
use ReflectionMethod;

/**
 * @author Jáchym Toušek <enumag@gmail.com>
 */
trait VerifierPresenterTrait
{
    use VerifierControlTrait;

    /**
     * @var Verifier
     */
    private $verifier;

    final public function injectVerifier(Verifier $verifier): void
    {
        $this->verifier = $verifier;
    }

    /**
     * @param ReflectionClass|ReflectionMethod $reflection
     */
    public function checkRequirements($reflection): void
    {
        $rules = $this->verifier->getRules($reflection);

        if ($rules !== [] && $reflection instanceof ReflectionMethod && substr($reflection->getName(), 0, 6) === 'render') {
            throw new NotSupportedException('Rules for render methods are not supported. Define the rules for action method instead.');
        }

        /** @var Request $request */
        $request = $this->getRequest();
        $this->verifier->checkRules($rules, $request);
    }

    public function getVerifier(): Verifier
    {
        return $this->verifier;
    }

    /**
     * Ensures that the action method exists.
     */
    protected function tryCall($method, array $parameters): bool
    {
        $called = parent::tryCall($method, $parameters);
        if (!$called && substr($method, 0, 6) === 'action') {
            $class = get_class($this);
            throw new BadRequestException("Action '$class::$method' does not exist.");
        }

        return $called;
    }

    /**
     * This method has to be public because it is called by VerifierControlTrait.
     *
     * @internal
     */
    public function delegateCreateRequest($component, $destination, array $parameters, $mode)
    {
        return parent::createRequest($component, $destination, $parameters, $mode);
    }
}
