<?php

/**
 * This file is part of the Arachne Verifier extenstion
 *
 * Copyright (c) Jáchym Toušek (enumag@gmail.com)
 *
 * For the full copyright and license information, please view the file license.md that was distributed with this source code.
 */

namespace Arachne\Verifier;

/**
 * @author Jáchym Toušek
 */
class Verifier extends \Nette\Object
{

	/** @var \Nette\Application\IPresenterFactory */
	protected $presenterFactory;

	/** @var \Doctrine\Common\Annotations\Reader */
	protected $reader;

	/** @var \Nette\DI\Container */
	protected $container;

	/** @var array<\Arachne\Verifier\IAnnotationHandler> */
	private $handlers;

	/**
	 * @param \Doctrine\Common\Annotations\Reader $reader
	 * @param \Nette\DI\Container $container $container
	 * @param \Nette\Application\IPresenterFactory $presenterFactory
	 */
	public function __construct(\Doctrine\Common\Annotations\Reader $reader, \Nette\DI\Container $container, \Nette\Application\IPresenterFactory $presenterFactory)
	{
		$this->reader = $reader;
		$this->container = $container;
		$this->presenterFactory = $presenterFactory;
		$this->handlers = [];
	}

	/**
	 * @param \ReflectionClass|\ReflectionMethod $annotations
	 * @param \Nette\Application\Request $request
	 * @throws \Nette\Application\ForbiddenRequestException
	 */
	public function checkAnnotations(\Reflector $reflection, \Nette\Application\Request $request)
	{
		if ($reflection instanceof \ReflectionMethod) {
			$requirements = $this->reader->getMethodAnnotation($reflection, 'Arachne\Verifier\Requirements');
		} elseif ($reflection instanceof \ReflectionClass) {
			$requirements = $this->reader->getClassAnnotation($reflection, 'Arachne\Verifier\Requirements');
		} else {
			throw new InvalidArgumentException('Reflection must be an instance of either \ReflectionMethod or \ReflectionClass.');
		}

		if ($requirements !== NULL) {
			foreach ($requirements->rules as $rule) {
				$class = $rule->getHandlerClass();
				if (!isset($this->handlers[$class])) {
					$this->handlers[$class] = $this->container->getByType($class);
					if (!$this->handlers[$class] instanceof IAnnotationHandler) {
						throw new InvalidStateException('Class \'' . get_class($this->handlers[$class]) . '\' does not implement \Arachne\Verifier\IAnnotationHandler interface.');
					}
				}
				$this->handlers[$class]->checkAnnotation($rule, $request);
			}
		}
	}

	/**
	 * @param \Nette\Application\Request $request
	 * @return bool
	 */
	public function isLinkAvailable(\Nette\Application\Request $request)
	{
		$presenter = $request->getPresenterName();
		$parameters = $request->getParameters();
		$presenterReflection = new \Nette\Application\UI\PresenterComponentReflection($this->presenterFactory->getPresenterClass($presenter));

		try {
			// Presenter requirements
			$this->checkAnnotations($presenterReflection, $request);

			// Action requirements
			$action = $parameters[\Nette\Application\UI\Presenter::ACTION_KEY];
			$method = 'action' . $action;
			$reflection = $presenterReflection->hasCallableMethod($method) ? $presenterReflection->getMethod($method) : NULL;
			if (!$reflection) {
				$method = 'render' . $action;
				$reflection = $presenterReflection->hasCallableMethod($method) ? $presenterReflection->getMethod($method) : NULL;
			}
			if ($reflection) {
				$this->checkAnnotations($reflection, $request);
			}

			// Signal requirements
			if (isset($parameters[\Nette\Application\UI\Presenter::SIGNAL_KEY])) {
				$signal = $parameters[\Nette\Application\UI\Presenter::SIGNAL_KEY];
				$method = 'handle' . ucfirst($signal);
				if ($presenterReflection->hasCallableMethod($method)) {
					$reflection = $presenterReflection->getMethod($method);
					$this->checkAnnotations($reflection, $request);
				}
			}

		} catch (\Nette\Application\ForbiddenRequestException $e) {
			return FALSE;
		}

		return TRUE;
	}

}
