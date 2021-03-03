<?php

namespace Yauhenko\RestBundle\Controller;

use ReflectionClass;
use ReflectionProperty;
use ReflectionNamedType;
use Yauhenko\RestBundle\TypesInterface;
use Yauhenko\RestBundle\Service\TypeScript;
use Yauhenko\RestBundle\Attributes\Api\Method;
use Yauhenko\RestBundle\Service\ClassResolver;
use Symfony\Component\HttpFoundation\Response;
use Yauhenko\RestBundle\Attributes\Common\Name;
use Symfony\Component\Routing\Annotation\Route;
use Doctrine\Common\Annotations\AnnotationReader;
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
	public function docs(ParameterBagInterface $params): Response {
		if($params->get('yauhenko.rest.env') === 'prod')
			throw new NotFoundHttpException('Not found');

		$resolver = new ClassResolver;
		$reader = new AnnotationReader;
		$ts = new TypeScript;
		$classes = $resolver->getReflections($params->get('yauhenko.rest.controllers_dir'));
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
				$access = $reader->getMethodAnnotation($rm, IsGranted::class);

				$params = [];

				if($info->getRequest()) {

					if(class_exists($info->getRequest())) {

						$rci = new ReflectionClass($info->getRequest());
						$defaults = $rci->getDefaultProperties();
						foreach($rci->getProperties(ReflectionProperty::IS_PUBLIC) as $rpi) {
							/** @var ReflectionNamedType $t */
							$t = $rpi->getType();

							if(!$name = $resolver->getAttribute($rpi, Name::class)) {
								/** @var Name|null $name */
								$name = $reader->getPropertyAnnotation($rpi, Name::class);
							}

							$name = $name ? $name->getValue() : null;
							$param = [
								'name' => $rpi->getName(),
								'description' => $name,
								'required' => false,
								'type' =>  $t->getName(),
								'nullable' => $t->allowsNull(),
								'default' => $defaults[$rpi->getName()] ?? null
							];

							if($reader->getPropertyAnnotation($rpi, NotBlank::class)) {
								$param['required'] = true;
							}

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
					'access' => $access ? $access->getAttributes() : null,
					'request' => [$info->getRequest(), $ts->getSlug($info->getRequest()), $info->getRequest() && class_exists($info->getRequest()) ? $ts->getInterfaceDefinition($info->getRequest()) : ''],
					'response' => [$info->getResponse(), $ts->getSlug($info->getResponse()), $info->getResponse() && class_exists($info->getResponse()) ? $ts->getInterfaceDefinition($info->getResponse()) : ''],
				];

				$controller['methods'][] = $method;
			}

			$controllers[] = $controller;

		}


		return $this->render('@Rest/docs.twig', ['docs' => $controllers]);

	}


	#[Route('/remote.ts')]
	public function remote(ParameterBagInterface $params): Response {

		if($params->get('yauhenko.rest.env') === 'prod')
			throw new NotFoundHttpException('Not found');

		$resolver = new ClassResolver;
		$ts = TypeScript::factory();

		/** @var TypesInterface $typesClass */
		$typesClass = $params->get('yauhenko.rest.types_class');
		if(class_exists($typesClass)) {
			$typesClass::register($ts);
		}

		$out = "import { rest, endpoint } from './rest-client';\n\n";
		$out .= $ts->getTypeScriptCode();
		$out .= "\n\n";

		$classes = $resolver->getReflections($params->get('yauhenko.rest.controllers_dir'));

		$export = [];

		foreach($classes as $rc) {

			if(!$classInfo = $resolver->getAttribute($rc, Controller::class)) continue;

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
					$args[] = "{$a}: number";
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

		$out = str_replace('public static uploadForm = (): Promise<IUpload> => rest.post(`/upload/form`);',
			'public static uploadForm = (request: FormData): Promise<IUpload> => rest.post(`/upload/form`, request);', $out);

		$out = str_replace('public static getUpload = (id: number)',
			'public static getUpload = (id: string)', $out);

		$out = str_replace('public static download = (id: number, name: number): Promise<unknown> => rest.get(`/download/${id}/${name}`);',
			'public static download = (id: string): void => { window.location.href = `${endpoint}/download/${id}`; }', $out);

		$out = str_replace('public static logoutByToken = (session: number)',
			'public static logoutByToken = (session: string)', $out);

		return new Response($ts->prettify($out, $params->get('yauhenko.rest.project_dir')) ?: $out, Response::HTTP_OK, [
			'Content-Type' => 'text/x.typescript'
		]);
	}


	#[Route('/rest.zip')]
	public function restZip(): BinaryFileResponse {
		return new BinaryFileResponse(__DIR__ . '/../../assets/rest.zip');
	}

}
