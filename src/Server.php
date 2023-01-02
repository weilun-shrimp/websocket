<?php

namespace Weilun\WebSocket;

use Socket;
use Weilun\WebSocket\Exceptions\FlowException;

class Server {
    protected string $address = '127.0.0.1';
    protected int $port = 8080;

    protected $master;    //socket的resource (php8.0 是 Websocket object)，即socket_create返回的資源


    public function __construct(string $address = '127.0.0.1', int $port = 8080)
    {
        $this->address = $address;
        $this->port = $port;
    }

    /**
     * Reference: https://www.php.net/manual/zh/function.socket-create.php
     * @throws FlowException
     */
    protected function create(int $domain = AF_INET, int $type = SOCK_STREAM, int $protocol = SOL_TCP)
    {
        $this->master = socket_create($domain, $type, $protocol);
        if (!$this->master) return throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error()));
        echo 'create socket successful ' . PHP_EOL;
    }

    /**
     * Reference: https://www.php.net/manual/zh/function.socket-get-option.php
     */
    protected function set_options()
    {
        /**
         * SO_REUSEADDR => allow multiple connections to the same port
         * 1 => indicate allow all the data package
         */
        // if (!socket_set_option($this->master, SOL_SOCKET, SO_REUSEADDR, 1))
        //     return throw new \Exception('set option failed. (SOL_SOCKET, SO_REUSEADDR, 1)' . PHP_EOL);
    }

    /**
     * Reference: https://www.php.net/manual/en/function.socket-bind.php
     * @throws FlowException
     */
    protected function bind()
    {
        if (!socket_bind($this->master, $this->address, $this->port))
            return throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($this->master)));

        echo 'socket bind successful.' . PHP_EOL .
            'address: ' . $this->address . PHP_EOL .
            'port: ' . $this->port . PHP_EOL;
    }

    /**
     * Reference: https://www.php.net/manual/en/function.socket-listen.php
     * @throws FlowException
     */
    protected function listen()
    {
        if (!socket_listen($this->master))
            return throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($this->master)));
        echo 'socket listen successful' . PHP_EOL;
    }

    /**
     * Reference: https://www.php.net/manual/en/function.socket-accept.php
     * 
     * @throws FlowException
     * @return Socket $client instance create by socket_accept
     */
    protected function accept()
    {
        $client = socket_accept($this->master);
        if (!$client) return throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($this->master)));
        echo 'socket accept successful' . PHP_EOL;
        return $client;
    }

    /**
     * status must be 101
     * Upgrade: websocket
     * Sec-WebSocket-Version: 13
     * Connection: Upgrade
     * Sec-WebSocket-Accept: **Hashed key**
     *
     * Sec-WebSocket-Accept Hashed key reference: https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Sec-WebSocket-Accept
     *
     * @param Socket $client -- create by socket_accept()
     * 
     * @throws FlowException
     * @return bool true means success
     */
    protected function hand_shake(Socket $client)
    {
        $data = socket_recv($client, $buff, 1000, 0);
        if ($data === false) return throw new FlowException(__FUNCTION__, 'recv error : ' . socket_strerror(socket_last_error($client)));
        echo 'hand shake buff : ' . PHP_EOL . $buff . PHP_EOL;

        $key_pos = strpos($buff, 'Sec-WebSocket-Key:');
        if ($key_pos === false) return throw new FlowException(__FUNCTION__, 'client key not set in client header.');
        $key_pos += 18;

        $temp = substr($buff, $key_pos, strlen($buff));
        $temp_key_end_pos = strpos($temp, "\r\n");
        if ($temp_key_end_pos === false) return throw new FlowException(__FUNCTION__, 'client key not correct set in client header. (key end pos)');

        $client_key = trim(substr($temp, 0, $temp_key_end_pos));
        if (!$client_key) return throw new FlowException(__FUNCTION__, 'client key is empty.');

        $hashed_key = base64_encode(sha1($client_key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $return = "HTTP/1.1 101 Switching Protocols\r\n";
        $return .= "Upgrade: websocket\r\n";
        $return .= "Sec-WebSocket-Version: 13\r\n";
        $return .= "Connection: Upgrade\r\n";
        $return .= "Sec-WebSocket-Accept: $hashed_key\r\n\r\n";

        $write = socket_write($client, $return, strlen($return));
        if ($write === false) return throw new FlowException(__FUNCTION__,  'write error : ' . socket_strerror(socket_last_error($client)));
        echo 'hand shaked' . PHP_EOL;
    }

    /**
     * socket_recv flag set 0 - not use any flag --- important
     */
    // protected function run()
    // {
    //     while (true) {
    //         $data = socket_recv($this->client, $buff, 1000, 0);
    //         if ($data === false) {
    //             echo 'disconnect' . PHP_EOL;
    //             throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($this->client)));
    //             break;
    //         }

    //         if ($data) {
    //             $client_msg = $this->unseal($buff);
    //             echo PHP_EOL . 'client content leng : ' . $data . PHP_EOL;
    //             echo 'client content : ' . PHP_EOL . $client_msg . PHP_EOL;
    //             var_dump($client_msg);
    //             $return = $this->seal('recv : ' . $client_msg);
    //             $write = socket_write($this->client, $return, strlen($return));
    //             if ($write === false) return throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($this->client)));
    //         }
    //     }
    // }

    protected function close(Socket $socket)
    {
        socket_close($socket);
    }

    /**
     *
     * 因為php 會對所有收到的訊息做一層遮罩直接看的話會是亂碼要先做解碼才看得懂
     * Reference : https://phppot.com/php/simple-php-chat-using-websocket/
     *  https://stackoverflow.com/questions/46720022/php-websocket-using-socket-recv-will-i-ever-receive-a-partial-frame
     */
    protected function unseal($socketData)
    {
        $length = ord($socketData[1]) & 127;
        if ($length == 126) {
            $masks = substr($socketData, 4, 4);
            $data = substr($socketData, 8);
        } elseif ($length == 127) {
            $masks = substr($socketData, 10, 4);
            $data = substr($socketData, 14);
        } else {
            $masks = substr($socketData, 2, 4);
            $data = substr($socketData, 6);
        }
        $return = '';
        for ($i = 0; $i < strlen($data); ++$i) {
            $return .= $data[$i] ^ $masks[$i % 4];
        }
        return $return;
    }

    /**
     * 因為php 會對所有送出的訊息做一層遮罩才送出, 做一層反向處裡(加二進制header)才能正常送出
     * Reference : https://phppot.com/php/simple-php-chat-using-websocket/
     *  https://stackoverflow.com/questions/46720022/php-websocket-using-socket-recv-will-i-ever-receive-a-partial-frame
     */
    protected function seal($socketData)
    {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($socketData);

        if ($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif ($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif ($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header . $socketData;
    }

    /**
     * Reference : https://www.php.net/manual/en/function.socket-recv.php
     * 
     * @throws FlowException
     * @return int number of bytes recv from socket
     */
    protected function recv(Socket $client, ?string &$buff, int $len = 1000, int $flag = 0)
    {
        $number_of_bytes = socket_recv($client, $buff, $len, $flag);
        if ($number_of_bytes === false) throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($client)));
        return $number_of_bytes;
    }

    /**
     * Reference : https://www.php.net/manual/en/function.socket-write.php
     * 
     * @throws FlowException
     * @return int number of bytes has writed to socket
     */
    protected function write(Socket $client, string $msg, ?int $len = null)
    {
        $number_of_bytes = socket_write($client, $msg, $len);
        if ($number_of_bytes === false) throw new FlowException(__FUNCTION__, socket_strerror(socket_last_error($client)));
        return $number_of_bytes;
    }

    // public function start()
    // {
    //     try {
    //         $this->create();
    //         $this->set_options();
    //         $this->bind();
    //         $this->listen();
    //         $this->accept();
    //         $this->hand_shake($this->client);
    //         $this->run();
    //     } catch (FlowException $e) {
    //         echo 'ERROR TYPE : ' . $e->getType() . PHP_EOL;
    //         echo 'ERROR MSG : ' . $e->getMessage() . PHP_EOL;
    //         $this->close($this->master);
    //     }
    // }
}