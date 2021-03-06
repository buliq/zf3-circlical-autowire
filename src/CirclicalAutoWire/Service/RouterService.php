<?php

namespace CirclicalAutoWire\Service;

use CirclicalAutoWire\Annotations\Route;
use CirclicalAutoWire\Model\AnnotatedRoute;
use Doctrine\Common\Annotations\AnnotationReader;
use Zend\Router\Http\TreeRouteStack;
use Doctrine\Common\Annotations\AnnotationRegistry;

/**
 * Class RouterService
 * @package CirclicalAutoWire\Service
 *
 * This service's purpose, is to bridge annotations with the Zend SM router
 */
final class RouterService
{
    private $router;

    private $reader;

    public static $routesParsed = 0;

    private $productionMode;

    private $annotations;

    public function __construct(TreeRouteStack $router, bool $productionMode)
    {
        AnnotationRegistry::registerAutoloadNamespace("CirclicalAutoWire\\Annotations", realpath(__DIR__ . "/../../"));
        $this->router = $router;
        $this->reader = new AnnotationReader();
        $this->productionMode = $productionMode;
        $this->annotations = [];
    }

    /**
     * Reset the annotations variable
     */
    public function reset()
    {
        $this->annotations = [];
    }

    /**
     * Parse a controller, storing results into the 'annotations' class variable
     *
     * @param string $controllerClass
     */
    public function parseController(string $controllerClass)
    {
        $class = new \ReflectionClass($controllerClass);
        /** @var Route $classAnnotation */
        $classAnnotation = $this->reader->getClassAnnotation($class, Route::class);

        // First, get all annotations for this controller

        /** @var \ReflectionMethod $method */
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() == $controllerClass) {
                $set = $this->reader->getMethodAnnotations($method);
                /** @var Route $routerAnnotation */
                foreach ($set as $routerAnnotation) {
                    if (!$routerAnnotation instanceof Route) {
                        continue;
                    }

                    if ($classAnnotation) {
                        $routerAnnotation->setPrefix($classAnnotation->value);
                    }
                    $routeName = $routerAnnotation->name ?? 'route-' . static::$routesParsed++;
                    if ($routerAnnotation->parent) {
                        $routeName = $routerAnnotation->parent . '/' . $routeName;
                    }
                    $this->annotations[$routeName] = new AnnotatedRoute($routerAnnotation, $controllerClass, $method->getName());
                }
            }
        }
    }

    /**
     * Compile routes into an array, simultaneously adding them to the router
     * @return array
     * @throws \Exception
     */
    public function compile(): array
    {
        ksort($this->annotations);

        /** @var AnnotatedRoute[] $routes */
        $routes = [];
        foreach ($this->annotations as $routeName => $annotatedRoute) {
            if (strpos($routeName, '/') === false) {
                $routes[$routeName] = $annotatedRoute;
            } else {
                $routePath = explode('/', $routeName);
                $baseRouteName = array_shift($routePath);
                if (!isset($routes[$baseRouteName])) {
                    throw new \Exception("An autowired route declares a parent of $baseRouteName, but $baseRouteName is not defined.");
                }

                $parentRoute = $routes[$baseRouteName];
                for ($i = 0; $i < count($routePath) - 1; $i++) {
                    $parentRoute = $parentRoute->getChild($routePath[$i]);
                }
                $parentRoute->addChild(end($routePath), $annotatedRoute);
            }
        }

        // Lastly, push all stacked routes into the router
        $routeConfig = [];
        foreach ($routes as $routeName => $annotatedRoute) {
            $routeParams = $annotatedRoute->toArray();
            $this->router->addRoute($routeName, $routeParams);
            $routeConfig[$routeName] = $routeParams;
        }

        return $routeConfig;
    }
}