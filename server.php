<?php

use Swoole\Http\Request;
use Swoole\Http\Response;

const WEBROOT = __DIR__ . '/web';

$subject_connnection_map = array();

error_reporting(E_ALL);

\Swoole\Coroutine\run(
    function () {
        $server = new Swoole\Coroutine\Http\Server('0.0.0.0', 9509, false);
//        $server = new Swoole\Coroutine\Http\Server('0.0.0.0', 9509, true);
//        $server->set(
//            [
//                'ssl_key_file' => __DIR__ . '/config/ssl.key',
//                'ssl_cert_file' => __DIR__ . '/config/ssl.crt',
//            ]
//        );

        $server->handle(
            '/',
            function (Request $req, Response $resp) {
                //websocket
                if (isset($req->header['upgrade']) and $req->header['upgrade'] == 'websocket') {
                    $resp->upgrade();
                    $resp->subjects = array();
                    while (true) {
                        $frame = $resp->recv();
                        if (empty($frame)) {
                            break;
                        }
                        $data = json_decode($frame->data, true);
                        var_dump($data);
                        switch ($data['cmd']) {
                            // 订阅主题
                            case 'subscribe':
                                $subject = $data['subject'];
                                subscribe($subject, $resp);
                                break;
                            // 向某个主题发布消息
                            case 'publish':
                                $subject = $data['subject'];
                                $event = $data['event'];
                                $data = $data['data'];
                                publish($subject, $event, $data, $resp);
                                break;
                        }
                    }
                    destry_connection($resp);
                    return;
                }
                //http
                $path = $req->server['request_uri'];
                if ($path == '/') {
                    $resp->end(exec_php_file(WEBROOT . '/index.html'));
                } else {
                    $file = realpath(WEBROOT . $path);
                    if (false === $file) {
                        $resp->status(404);
                        $resp->end('<h3>404 Not Found</h3>');
                        return;
                    }
                    // Security check! Very important!!!
                    if (strpos($file, WEBROOT) !== 0) {
                        $resp->status(400);
                        return;
                    }
                    if (\pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                        $resp->end(exec_php_file($file));
                        return;
                    }

                    if (isset($req->header['if-modified-since']) and !empty($if_modified_since = $req->header['if-modified-since'])) {
                        // Check 304.
                        $info = \stat($file);
                        $modified_time = $info ? \date(
                                'D, d M Y H:i:s',
                                $info['mtime']
                            ) . ' ' . \date_default_timezone_get() : '';
                        if ($modified_time === $if_modified_since) {
                            $resp->status(304);
                            $resp->end();
                            return;
                        }
                    }
                    $resp->sendfile($file);
                }
            }
        );

        $server->start();
    }
);


// 订阅
function subscribe($subject, $connection)
{
    global $subject_connnection_map;
    $connection->subjects[$subject] = $subject;
    $subject_connnection_map[$subject][$connection->fd] = $connection;
}

// 取消订阅
function unsubscribe($subject, $connection)
{
    global $subject_connnection_map;
    unset($subject_connnection_map[$subject][$connection->fd]);
}

// 向某个主题发布事件
function publish($subject, $event, $data, $exclude)
{
    global $subject_connnection_map;
    if (empty($subject_connnection_map[$subject])) {
        return;
    }
    foreach ($subject_connnection_map[$subject] as $connection) {
        if ($exclude == $connection) {
            continue;
        }
        $connection->push(
            json_encode(
                array(
                    'cmd' => 'publish',
                    'event' => $event,
                    'data' => $data
                )
            )
        );
    }
}

// 清理主题映射数组
function destry_connection($connection)
{
    foreach ($connection->subjects as $subject) {
        unsubscribe($subject, $connection);
    }
}

function exec_php_file($file)
{
    \ob_start();
    // Try to include php file.
    try {
        include $file;
    } catch (\Exception $e) {
        echo $e;
    }
    return \ob_get_clean();
}


