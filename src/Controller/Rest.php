<?php

namespace Yauhenko\RestBundle\Controller;

use Yauhenko\RestBundle\Service\TypeScript;
use Yauhenko\RestBundle\Attributes\Api\Method;
use Yauhenko\RestBundle\Service\ClassResolver;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Yauhenko\RestBundle\Attributes\Api\Controller;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class Rest extends AbstractController {

	#[Route('/rest.ts')]
	public function remote(ParameterBagInterface $params): Response {

		if(!$params->get('yauhenko.rest.ts_enabled'))
			throw new NotFoundHttpException('Not found');

		$resolver = new ClassResolver;
		$ts = TypeScript::factory();

		$typesClass = $params->get('yauhenko.rest.types_class');

		if(class_exists($typesClass) && method_exists($typesClass, 'registerTypes')) {
			call_user_func([$typesClass, 'registerTypes'], $ts);
		}

        $out = file_get_contents(__DIR__ . '/../../assets/rest-template.ts');
        $out .= $ts->getTypeScriptCode();
        $out .= "\n\n";

		$classes = $resolver->getReflections($params->get('yauhenko.rest.controllers_dir'));

        $api = '';

        foreach($classes as $rc) {

			if(!$resolver->getAttribute($rc, Controller::class)) continue;

			$classRoute = $resolver->getAttribute($rc, Route::class);

			$alias = str_replace('App\Controller\\', '', $rc->getName());

            $out .= "class {$alias} {\n\tprivate api: RestAPI;\n\tconstructor(api: RestAPI) {\n\t\tthis.api = api;\n\t}\n";

            $api .= "\n\t/** Get {$alias} API */\n\tget {$alias}(): {$alias} {\n\treturn (this.instances['{$alias}'] as {$alias}) ?? (this.instances['{$alias}'] = new {$alias}(this));\n\t}\n";

			foreach($rc->getMethods() as $rm) {

				$route = $resolver->getAttribute($rm, Route::class);
                /** @var Method $info */
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

                $out .= "\n\t/** " . ($info->getTitle() ?: $rm->getName()) . " */\n\t" . $rm->getName() . " = (" . implode(', ', $args) . "): Promise<" . ($info->getResponse() ? $ts->getSlug($info->getResponse()) : 'unknown') . "> => ";
                $path = str_replace('{', '${', ($classRoute ?  $classRoute->getPath() : '') .  $route->getPath());
                $out .= "this.api." . strtolower($route->getMethods() ? $route->getMethods()[0] : 'post') . "(`" . $path . "`" . ($request ? ", request" : '') . ");\n";

			}

			$out .= "}\n\n";

		}

        $out = str_replace('//INCLUDE', $api, $out);

		if(class_exists($typesClass) && method_exists($typesClass, 'codePostProcessor')) {
			$out = call_user_func([$typesClass, 'codePostProcessor'], $out);
		}

		return new Response($ts->prettify($out, $params->get('yauhenko.rest.cache_dir')) ?: $out, Response::HTTP_OK, [
			'Content-Type' => 'text/x.typescript'
		]);
	}

}
