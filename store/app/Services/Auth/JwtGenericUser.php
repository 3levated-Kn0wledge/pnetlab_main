<?php

namespace App\Services\Auth;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;

class JwtGenericUser implements UserContract
{
    /**
     * All of the user's attributes.
     *
     * @var array
     */
    protected $attributes;

    /**
     * Create a new generic User object.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes)
    {
        $this->attributes = $attributes;
    }

    /**
     * Get the name of the unique identifier for the user.
     *
     * @return string
     */
    public function getAuthIdentifierName()
    {
    }

    /**
     * Get the unique identifier for the user.
     *
     * @return mixed
     */
    public function getAuthIdentifier()
    {
    }

    /**
     * Get the name of the password column for the user.
     *
     * Required by Illuminate\Contracts\Auth\Authenticatable since Laravel 11;
     * without it this class cannot be declared at all, and the failure is a
     * fatal at the point Auth resolves the guard rather than anything that
     * looks like an upgrade problem.
     *
     * The framework asks for this so it can rehash a stored password whose work
     * factor has fallen behind. It is answered honestly -- 'authen_pass' is the
     * column JwtUserProvider::validateCredentials() reads -- but see
     * JwtUserProvider::rehashPasswordIfRequired() for why nothing acts on it.
     *
     * @return string
     */
    public function getAuthPasswordName()
    {
        return AUTHEN_PASS;
    }

    /**
     * Get the password for the user.
     *
     * @return string
     */
    public function getAuthPassword()
    {
    }

    /**
     * Get the "remember me" token value.
     *
     * @return string
     */
    public function getRememberToken()
    {
    }

    /**
     * Set the "remember me" token value.
     *
     * @param  string  $value
     * @return void
     */
    public function setRememberToken($value)
    {
    }

    /**
     * Get the column name for the "remember me" token.
     *
     * @return string
     */
    public function getRememberTokenName()
    {
    }

    /**
     * Dynamically access the user's attributes.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($key)
    {
        if(isset($this->attributes[$key]))
            return $this->attributes[$key];
        else{
            return null;
        }
    }

    /**
     * Dynamically set an attribute on the user.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return void
     */
    public function __set($key, $value)
    {
        $this->attributes[$key] = $value;
    }

    /**
     * Dynamically check if a value is set on the user.
     *
     * @param  string  $key
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Dynamically unset a value on the user.
     *
     * @param  string  $key
     * @return void
     */
    public function __unset($key)
    {
        unset($this->attributes[$key]);
    }
    
    public function getAttr(){
        return $this->attributes;
    }
}
