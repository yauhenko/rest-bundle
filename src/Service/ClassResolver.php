<?php

namespace Yauhenko\RestBundle\Service;

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

class ClassResolver {

	/**
	 * @return ReflectionClass[]
	 */
	public function getReflections(string $dir, string $appNamespace = 'App'): array {
		$result = $this->getNames($dir, $appNamespace);
		array_walk($result, function(string &$class) {
			$class = new ReflectionClass($class);
		});
		return $result;
	}

	public function getNames(string $dir, string $appNamespace = 'App'): array {
		$result = [];
		$it = new RecursiveDirectoryIterator($dir);
		foreach(new RecursiveIteratorIterator($it) as $file) {
			if(preg_match('/\.php$/', $file)) {
				$class = $appNamespace . str_replace(['.php', '/'], ['', '\\'], preg_replace('/^.+\/src\//', '/', $file));
				if(!class_exists($class)) {
					$data = file_get_contents($file);
					preg_match('/namespace\s+(.+);/isU', $data, $ns);
					$ns = trim($ns[1] ?? '');
					$class = ($ns ? $ns . '\\' : '') . pathinfo($file, PATHINFO_FILENAME);
					if(!class_exists($class)) continue;
				}
				$result[] = $class;
			}
		}
		return $result;
	}

	public function getAttribute(ReflectionClass|ReflectionProperty|ReflectionMethod $reflection, string $name): ?object {
		if($a = $reflection->getAttributes($name)) {
			return $a[0]->newInstance();
		} else {
			return null;
		}
	}

	public function getAttributes(ReflectionClass|ReflectionProperty|ReflectionMethod $reflection, string $name): array {
		$attributes = $reflection->getAttributes($name);
		foreach($attributes as $k => $a) {
			$attributes[$k] = $a->newInstance();
		}
		return $attributes;
	}

}
