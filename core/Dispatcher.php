<?php
declare(strict_types=1);

namespace dispatcher;

use \ReflectionMethod;

final class Dispatcher
{
    public function __construct()
    {
        if (!defined('_ROOT')) {
            define('_ROOT', $this->getRootPath());
        }
    }

    private function getRootPath(): string
    {
        if (isset($_SERVER['DOCUMENT_ROOT'])) {
            return dirname($_SERVER['DOCUMENT_ROOT']);
        }
        if (isset($_SERVER['SCRIPT_FILENAME'])) {
            $commonLength = strspn($_SERVER['SCRIPT_FILENAME'] ^ __FILE__, "\0");
            return substr(__FILE__, 0, $commonLength - 1);
        }
        return dirname(__DIR__);
    }

    public function run(): void
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        $segments = array_filter(explode('/', trim(strtok($requestUri, '?'), '/')));
        $method = ucfirst(strtolower(getenv('REQUEST_METHOD') ?: 'GET'));

        $controllerName = !empty($segments[0])
            ? ucfirst(strtolower($segments[0])) . 'Controller'
            : 'IndexController';
        $action = !empty($segments[1]) ? strtolower($segments[1]) : 'index';
        $params = array_slice($segments, 2);

        $call = $this->dispatcher($controllerName, $action, $method, $params);

        $this->handleResponse($call);
    }

    private function dispatcher(string $controllerName, string $action, string $method, array $params)
    {
        $controllerFile = _ROOT . "/controllers/{$controllerName}.php";
        if (!file_exists($controllerFile)) return 404;
        require_once $controllerFile;
        $class = "\\controllers\\{$controllerName}";
        $function = "{$action}{$method}";
        if (!class_exists($class)) return "控制器类 {$class} 未定义";
        if (!method_exists($class, $function)) return "控制器方法 {$class}->{$function}() 不存在";

        try {

            $controller = new $class($action, $method);
            $refs = new ReflectionMethod($controller, $function);
            foreach ($refs->getParameters() as $i => $parameter) {
                $paramType = $parameter->getType()?->getName();
                $params[$i] = match ($paramType) {
                    'int' => intval($params[$i] ?? 0),
                    'float' => floatval($params[$i] ?? 0),
                    'string' => strval($params[$i] ?? ''),
                    'bool' => boolval($params[$i] ?? 0),
                    default => ($params[$i] ?? null),
                };
            }

            return $controller->{$function}(...$params);

        } catch (\Exception $e) {
            return $e->getMessage();
        }
    }

    private function handleResponse($call): void
    {
        if (is_string($call)) {
            $code = 500;
            $json = ['error' => 1, 'message' => $call];

        } elseif (is_int($call)) {
            $code = $call;
            $json = ['error' => 1, 'message' => "error code={$call}"];

        } elseif (is_bool($call)) {
            $code = $call ? 200 : 500;
            $json = ['error' => $call ? 0 : 1, 'message' => $call ? 'SUCCESS' : 'FAIL'];

        } else {
            $code = 200;
            $json = ['error' => 0, 'data' => $call];
        }

        http_response_code($code);
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode($json, JSON_UNESCAPED_UNICODE);

    }
}