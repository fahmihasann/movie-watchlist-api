<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

trait AssertsApiError
{
    protected function assertApiError(
        TestResponse $response,
        int $status,
        ?string $message = null,
    ): TestResponse {
        $response->assertStatus($status)
            ->assertHeader('content-type', 'application/json');

        $payload = $response->json();

        $this->assertIsArray($payload);
        $this->assertArrayHasKey('message', $payload);
        $this->assertIsString($payload['message']);
        $this->assertNotSame('', trim($payload['message']));

        $this->assertSame(
            [],
            array_values(array_diff(array_keys($payload), ['message', 'errors'])),
            'Error responses may only contain the message and errors keys.',
        );

        if ($message !== null) {
            $this->assertSame($message, $payload['message']);
        }

        return $response;
    }
}
