<?php

namespace Dq\Rest\Exceptions;

use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    public function render($request, Exception $e)
    {
        if ($request->isXmlHttpRequest() &&
            ($e instanceof \Symfony\Component\Debug\Exception\FatalErrorException ||
            $e instanceof \PDOException)
        ) {
            if (config('app.debug')) {
                return response()->json([
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                    'line' => $e->getLine(),
                    'trace' => collect($e->getTrace())->map(function($item) {
                        if (!isset($item['file'])) {
                            return null;
                        }
                        return "{$item['file']}:{$item['line']}";
                    })->filter(function($item) { return !empty($item); })->values(),
                ], 500);
            }
            else {
                if ($e instanceof \PDOException) {
                   return response()->json(['error' => 'Error in database connection or query'], 500);
                }
                if ($e instanceof \Symfony\Component\Debug\Exception\FatalErrorException) {
                   return response()->json(['error' => 'Whoops! Something went wrong.'], 500);
                }
            }
        }
        return parent::render($request, $e);
    }
}
