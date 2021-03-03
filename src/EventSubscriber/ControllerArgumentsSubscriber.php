<?php

namespace Yauhenko\RestBundle\EventSubscriber;

use ReflectionClass;
use Yauhenko\RestBundle\Service\ObjectBuilder;
use Yauhenko\RestBundle\Attributes\Api\RequestModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;

class ControllerArgumentsSubscriber implements EventSubscriberInterface {

	protected ObjectBuilder $builder;

	public function __construct(ObjectBuilder $builder) {
		$this->builder = $builder;
	}

	public function onControllerArgumentsEvent(ControllerArgumentsEvent $event) {
		$args = $event->getArguments();
		$request = $event->getRequest();
		foreach($args as $idx => $arg) {
			if(!is_object($arg)) continue;
			$class = get_class($arg);
			if(class_exists($class)) {
				$rc = new ReflectionClass($class);
				if($attrs = $rc->getAttributes(RequestModel::class)) {
					$args[$idx] = $this->builder->build($class, $request->request->all());
				}
			}
		}
		$event->setArguments($args);
	}

	public static function getSubscribedEvents(): array {
		return [ControllerArgumentsEvent::class => 'onControllerArgumentsEvent'];
	}

}
