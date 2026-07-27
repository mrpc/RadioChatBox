<?php

namespace RadioChatBox\Tests\Http;

use PHPUnit\Framework\TestCase;
use Pramnos\Http\Response;
use RadioChatBox\Http\Validate;

/**
 * Covers the RadioChatBox\Http\Validate bridge to the framework Validator: it
 * returns null when valid, or a 400 {success:false, error:<first message>,
 * errors:{...}} response (the app's existing error contract) when invalid.
 */
class ValidateTest extends TestCase
{
    public function testReturnsNullWhenValid(): void
    {
        $this->assertNull(Validate::check(['name' => 'x'], ['name' => 'required']));
    }

    public function testReturns400WithFirstMessageAndErrorsMap(): void
    {
        $resp = Validate::check(
            ['age' => '5'],
            ['age' => 'integer|min:18|max:120'],
            ['age.min' => 'Age must be between 18 and 120']
        );

        $this->assertInstanceOf(Response::class, $resp);
        $this->assertSame(400, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertFalse($body['success']);
        $this->assertSame('Age must be between 18 and 120', $body['error']);
        $this->assertArrayHasKey('age', $body['errors']);
    }

    /**
     * Numeric range validation works on the STRING values forms deliver — the
     * whole reason the framework Validator's numeric size fix was needed.
     */
    public function testNumericRangeAppliesToFormStrings(): void
    {
        $this->assertNull(Validate::check(['age' => '25'], ['age' => 'integer|min:18|max:120']));
        $this->assertSame(400, Validate::check(['age' => '200'], ['age' => 'integer|min:18|max:120'])->getStatusCode());
        $this->assertSame(400, Validate::check(['age' => '15'], ['age' => 'integer|min:18|max:120'])->getStatusCode());
    }
}
