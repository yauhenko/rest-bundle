<?php

namespace Yauhenko\RestBundle\EventSubscriber;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class RequestSubscriber implements EventSubscriberInterface {

	public function onRequestEvent(RequestEvent $event) {
		$request = $event->getRequest();
		//$request->setLocale('en');
		$body = $request->getContent() ?: $request->query->get('__payload');
		if($request->getMethod() === 'OPTIONS') {
			$response = new Response(null);
			$event->setResponse($response);
		} elseif($body) {
			$data = json_decode($body, true);
			if(!is_array($data)) {
				$event->setResponse(new JsonResponse(['error' => 'Malformed JSON'], 400));
				return;
			}
		} elseif($request->getMethod() === 'GET') {
			$data = $request->query->all();
		}
		if(isset($data)) {
			array_walk_recursive($data, function(&$value) {
				if(is_string($value)) $value = trim($value);
			});
			$request->request->replace($data);
		}
	}

	public static function getSubscribedEvents(): array {
		return [RequestEvent::class => ['onRequestEvent', 5000]];
	}

}
