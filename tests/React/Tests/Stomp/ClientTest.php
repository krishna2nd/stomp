<?php

namespace React\Tests\Stomp;

use React\Stomp\Client;
use React\Stomp\Io\InputStreamInterface;
use React\Stomp\Io\OutputStreamInterface;
use React\Stomp\Protocol\Frame;

class ClientTest extends TestCase
{
    /** @test */
    public function itShouldSendConnectFrameOnCreation()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->once())
            ->method('sendFrame')
            ->with($this->frameIsEqual(new Frame('CONNECT', array('accept-version' => '1.1', 'host' => 'localhost'))));

        $client = new Client($input, $output, array('vhost' => 'localhost'));
    }

    /** @test */
    public function itShouldEmitReadyAfterHandshake()
    {
        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = new Client($input, $output, array('vhost' => 'localhost'));
        $client->on('ready', $this->expectCallableOnce());

        $frame = new Frame('CONNECTED', array('session' => '1234', 'server' => 'React/alpha'));
        $input->emit('frame', array($frame));
    }

    /** @test */
    public function itShouldChangeToConnectedStateWhenReceivingConnectedResponse()
    {
        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
    }

    /** @test */
    public function sendShouldSend()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SEND', array('destination' => '/foo', 'content-length' => '5', 'content-type' => 'text/plain'), 'hello')
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->send('/foo', 'hello');
    }

    /**
     * @test
     * @dataProvider provideAvailableAckMethods
     */
    public function subscribeMustIncludeAValidAckMethod($ack)
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SUBSCRIBE', array('id' => 0, 'destination' => '/foo', 'ack' => $ack))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->subscribe('/foo', $callback, $ack);
    }

    public function provideAvailableAckMethods()
    {
        return array(
            array('auto'),
            array('client'),
            array('client-individual'),
        );
    }

    /** @test */
    public function subscribeHasUniqueIdHeader()
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameHasHeader('id', 0));

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameHasHeader('id', 1));

        $client = $this->getConnectedClient($input, $output);
        $client->subscribe('/foo', $callback);
        $client->subscribe('/bar', $callback);
    }

    /** @test */
    public function subscribeCanEmbedCustomHeader()
    {
        $callback = $this->createCallableMock();

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('SUBSCRIBE', array('foo' => 'bar', 'id' => 0, 'destination' => '/foo', 'ack' => 'auto'))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->subscribe('/foo', $callback, 'auto', array('foo' => 'bar'));
    }

    /**
     * @test
     * @dataProvider getAcknowledgeableAckModes
     */
    public function subscribeCallbackHasAcknowledgementParameterIfAckIsNotAuto($ack)
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }));

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribe('/foo', $callback, $ack);

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => $subscriptionId, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));

        $this->assertInstanceOf('React\Stomp\AckResolver', $capturedResolver);
    }

    public function getAcknowledgeableAckModes()
    {
        return array(
            array('client'),
            array('client-individual'),
        );
    }

    /** @test */
    public function acknowledgeWithAckResolverParameter()
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }));

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', array('subscription' => 0, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribe('/foo', $callback, 'client');

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => $subscriptionId, 'message-id' => 54321, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));

        $capturedResolver->ack();
    }

    /** @test */
    public function negativeAcknowledgeWithAckResolverParameter()
    {
        $capturedResolver = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(1))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame, $resolver) use (&$capturedResolver) {
                $capturedResolver = $resolver;
            }));

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $output
            ->expects($this->at(2))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', array('subscription' => 0, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribe('/foo', $callback, 'client');

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => $subscriptionId, 'message-id' => 54321, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));

        $capturedResolver->nack();
    }

    /** @test */
    public function messagesShouldGetRoutedToSubscriptions()
    {
        $capturedFrame = null;

        $callback = $this->createCallableMock();
        $callback
            ->expects($this->exactly(2))
            ->method('__invoke')
            ->will($this->returnCallback(function ($frame) use (&$capturedFrame) {
                $capturedFrame = $frame;
            }));

        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $subscriptionId = $client->subscribe('/foo', $callback);

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => $subscriptionId, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => $subscriptionId, 'message-id' => 43, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));

        $this->assertInstanceOf('React\Stomp\Protocol\Frame', $capturedFrame);
        $this->assertFrameEquals($responseFrame, $capturedFrame);

        return array($input, $output, $client, $capturedFrame);
    }

    /**
    * @test
    * @depends messagesShouldGetRoutedToSubscriptions
    */
    public function callbackShouldNotBeCalledAfterUnsubscribe($data)
    {
        list($input, $output, $client, $capturedFrame) = $data;

        $client->unsubscribe($capturedFrame->getHeader('subscription'));

        $responseFrame = new Frame(
            'MESSAGE',
            array('subscription' => 0, 'message-id' => 42, 'destination' => '/foo', 'content-type' => 'text/plain'),
            'this is a published message'
        );
        $input->emit('frame', array($responseFrame));
    }

    /** @test */
    public function shouldAck()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', array('subscription' => 12345, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->ack(12345, 54321);
    }

    /** @test */
    public function shouldAckWithCustomHeaders()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('ACK', array('foo' => 'bar', 'subscription' => 12345, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->ack(12345, 54321, array('foo' => 'bar'));
    }

    /** @test */
    public function shouldNack()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', array('subscription' => 12345, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->nack(12345, 54321);
    }

    /** @test */
    public function shouldNackWithCustomHeaders()
    {
        $input = $this->createInputStreamMock();

        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');
        $output
            ->expects($this->at(1))
            ->method('sendFrame')
            ->with($this->frameIsEqual(
                new Frame('NACK', array('foo' => 'bar', 'subscription' => 12345, 'message-id' => 54321))
            ));

        $client = $this->getConnectedClient($input, $output);
        $client->nack(12345, 54321, array('foo' => 'bar'));
    }

    /** @test */
    public function disconnectShouldGracefullyDisconnect()
    {
        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getMockBuilder('React\Stomp\Client')
            ->setConstructorArgs(array($input, $output, array('vhost' => 'localhost')))
            ->setMethods(array('generateReceiptId'))
            ->getMock();
        $client
            ->expects($this->once())
            ->method('generateReceiptId')
            ->will($this->returnValue('1234'));
        $input->emit('frame', array(new Frame('CONNECTED')));
        $client->disconnect();

        $output
            ->expects($this->once())
            ->method('close');

        $input->emit('frame', array(new Frame('RECEIPT', array('receipt-id' => '1234'))));
    }

    /** @test */
    public function processingErrorShouldResultInClientError()
    {
        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->on('error', $this->expectCallableOnce());

        $input->emit('frame', array(new Frame('ERROR', array('message' => 'whoops'))));
    }

    /** @test */
    public function inputErrorShouldResultInClientError()
    {
        $input = $this->createInputStreamMock();
        $output = $this->getMock('React\Stomp\Io\OutputStreamInterface');

        $client = $this->getConnectedClient($input, $output);
        $client->on('error', $this->expectCallableOnce());

        $e = new \Exception('Input had a problem.');
        $input->emit('error', array($e));
    }

    private function getConnectedClient(InputStreamInterface $input, OutputStreamInterface $output)
    {
        $client = new Client($input, $output, array('vhost' => 'localhost'));
        $input->emit('frame', array(new Frame('CONNECTED')));

        return $client;
    }

    private function createInputStreamMock()
    {
        return $this->getMockBuilder('React\Stomp\Io\InputStream')
            ->setConstructorArgs(array($this->getMock('React\Stomp\Protocol\Parser')))
            ->setMethods(array('isWritable', 'write', 'end', 'close'))
            ->getMock();
    }
}
