<?php

namespace Yauhenko\RestBundle\EventSubscriber;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Error;

class ExceptionSubscriber implements EventSubscriberInterface {

	protected ParameterBagInterface $params;

	public function __construct(ParameterBagInterface $params) {
		$this->params = $params;
	}

	public function onKernelException(ExceptionEvent $event) {

		$throwable = $event->getThrowable();

		$code = $throwable instanceof Error ? 500 : $throwable->getCode();

		if($code < 400 || $code > 499) $code = 400;

		if($throwable instanceof BadRequestHttpException) $code = 400;
		elseif($throwable instanceof AuthenticationException) $code = 401;
		elseif($throwable instanceof AccessDeniedException) $code = 403;
		elseif($throwable instanceof AccessDeniedHttpException) $code = 403;
		elseif($throwable instanceof NotFoundHttpException) $code = 404;

		$message = $throwable->getMessage();

		if(preg_match('/^.+\\\([A-z]+) object not found by the @ParamConverter annotation.$/', $message, $m)) {
			$message = "{$m[1]} not found";
		} elseif(preg_match('/Access Denied/i', $message)) {
			$message = 'Access Denied';
		}

		$res = [
			'error' => $message,
			'code' => $code,
		];

		if($this->params->get('env') === 'dev') {
			$res['trace'] = $throwable->getTrace();
		}

		$event->setResponse(new JsonResponse($res, $code));

	}

	public static function getSubscribedEvents(): array {
		return [ExceptionEvent::class => 'onKernelException'];
	}

}
