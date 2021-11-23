<?php

namespace Yauhenko\RestBundle\Controller;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use Yauhenko\RestBundle\Service\TypeScript;
use Yauhenko\RestBundle\Attributes\Api\Method;
use Yauhenko\RestBundle\Service\ClassResolver;
use Symfony\Component\HttpFoundation\Response;
use Yauhenko\RestBundle\Attributes\Common\Name;
use Symfony\Component\Routing\Annotation\Route;
use Yauhenko\RestBundle\Attributes\Api\Controller;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

#[Route('/docs')]
class Docs extends AbstractController {

	#[Route]
	public function docs(ParameterBagInterface $parameterBag): Response {
		if(!$parameterBag->get('yauhenko.rest.docs_enabled'))
			throw new NotFoundHttpException('Not found');

		$resolver = new ClassResolver;
		$ts = new TypeScript;
		$classes = $resolver->getReflections($parameterBag->get('yauhenko.rest.controllers_dir'));
		$controllers = [];

		foreach($classes as $rc) {

			if(!$info = $resolver->getAttribute($rc, Controller::class)) continue;
			$route = $resolver->getAttribute($rc, Route::class);

			$controller = [
				'id' => md5($rc->getName()),
				'title' => $info->getTitle(),
				'description' => $info->getDescription(),
				'prefix' => $route ? $route->getPath() : '',
				'methods' => [],
			];

			foreach($rc->getMethods() as $rm) {

				$info = $resolver->getAttribute($rm, Method::class);
				$route = $resolver->getAttribute($rm, Route::class);

				if(!$route || !$info) continue;

				/** @var IsGranted $access */
				$access = $resolver->getAttribute($rm, IsGranted::class);

				$params = [];

				if($info->getRequest()) {

					if(class_exists($info->getRequest())) {

						$rci = new ReflectionClass($info->getRequest());
						$defaults = $rci->getDefaultProperties();
						foreach($rci->getProperties(ReflectionProperty::IS_PUBLIC) as $rpi) {
							/** @var ReflectionNamedType $t */
							$t = $rpi->getType();
							$name = $resolver->getAttribute($rpi, Name::class);
							$name = $name?->getValue();
							$param = [
								'name' => $rpi->getName(),
								'description' => $name,
								'required' => false,
								'type' =>  $t->getName(),
								'nullable' => $t->allowsNull(),
								'default' => $defaults[$rpi->getName()] ?? null
							];

							if($rpi->getAttributes(NotBlank::class)) {
								$param['required'] = true;
							}

							$params[] = $param;
						}
					}

				}

				$method = [
					'id' => md5($rc->getName() . $rm->getName()),
					'name' => $info->getTitle(),
					'route' => $controller['prefix'] . $route->getPath(),
					'methods' => $route->getMethods(),
					'params' => $params,
					'access' => $access?->getAttributes(),
					'request' => [$info->getRequest(), $ts->getSlug($info->getRequest()), $info->getRequest() && class_exists($info->getRequest()) ? $ts->getInterfaceDefinition($info->getRequest()) : ''],
					'response' => [$info->getResponse(), $ts->getSlug($info->getResponse()), $info->getResponse() && class_exists($info->getResponse()) ? $ts->getInterfaceDefinition($info->getResponse()) : ''],
				];

				$controller['methods'][] = $method;
			}

			$controllers[] = $controller;

		}

		return $this->render('@Rest/docs.twig', [
			'docs' => $controllers,
			'ts_enabled' => $parameterBag->get('yauhenko.rest.ts_enabled'),
			'logo' => $parameterBag->get('yauhenko.rest.logo'),
			'title' => $parameterBag->get('yauhenko.rest.title'),
		]);

	}

	#[Route('/remote.ts')]
	public function remote(ParameterBagInterface $params): Response {

		if(!$params->get('yauhenko.rest.ts_enabled'))
			throw new NotFoundHttpException('Not found');

		$resolver = new ClassResolver;
		$ts = TypeScript::factory();

		$typesClass = $params->get('yauhenko.rest.types_class');

		if(class_exists($typesClass) && method_exists($typesClass, 'registerTypes')) {
			call_user_func([$typesClass, 'registerTypes'], $ts);
		}

		$out = "import { rest, endpoint } from './rest-config';\n\n";
		$out .= "if (rest.debug) console.info('REST Endpoint', endpoint);\n\n";
		$out .= $ts->getTypeScriptCode();
		$out .= "\n\n";

		$classes = $resolver->getReflections($params->get('yauhenko.rest.controllers_dir'));

		$export = [];

		foreach($classes as $rc) {

			if(!$resolver->getAttribute($rc, Controller::class)) continue;

			$classRoute = $resolver->getAttribute($rc, Route::class);

			$alias = str_replace('App\Controller\\', '', $rc->getName());

			$out .= "class {$alias} {\n\n";

			$export[] = "{$alias}";

			foreach($rc->getMethods() as $rm) {

				$route = $resolver->getAttribute($rm, Route::class);
				$info = $resolver->getAttribute($rm, Method::class);

				if(!$info || !$route) continue;

				$args = [];
				$request = null;

				preg_match_all('/\{([A-z0-9]+)\}/isU', $route->getPath(), $m, PREG_PATTERN_ORDER);
				foreach($m[1] as $a) {
					$args[] = "{$a}: TIdentifier";
				}

				if($info->getRequest()) {
					if(class_exists($info->getRequest())) {
						$request = true;
						$args[] = 'request: ' . $ts->getSlug($info->getRequest());
					}
				}

				$out .= "\tpublic static " . $rm->getName() . " = (" . implode(', ', $args) . "): Promise<" . ($info->getResponse() ? $ts->getSlug($info->getResponse()) : 'unknown') . "> => ";
				$path = str_replace('{', '${', ($classRoute ?  $classRoute->getPath() : '') .  $route->getPath());
				$path = str_replace('/api', '', $path);
				$out .= "rest." . strtolower($route->getMethods() ? $route->getMethods()[0] : 'post') . "(`" . $path . "`" . ($request ? ", request" : '') . ");\n";
				$out .= "\n";

			}

			$out .= "}\n\n";

		}

		$out .= "export const API = { " . implode(', ', $export) . " }\n";

		if(class_exists($typesClass) && method_exists($typesClass, 'codePostProcessor')) {
			$out = call_user_func([$typesClass, 'codePostProcessor'], $out);
		}

		return new Response($ts->prettify($out, $params->get('yauhenko.rest.cache_dir')) ?: $out, Response::HTTP_OK, [
			'Content-Type' => 'text/x.typescript'
		]);
	}


	#[Route('/rest.zip')]
	public function restZip(ParameterBagInterface $params): BinaryFileResponse {
		if(!$params->get('yauhenko.rest.ts_enabled'))
			throw new NotFoundHttpException('Not found');
		return new BinaryFileResponse(__DIR__ . '/../../assets/rest.zip');
	}

}
