<?php

namespace App\Services\Auth;

use Illuminate\Support\Str;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Auth\Authenticatable as Authenticatable;
use Illuminate\Support\Facades\Hash;
use App\Helpers\Request\Checker;

class JwtUserProvider implements UserProvider
{
/**
   * The Mongo User Model
   */
  private $model;
 
  /**
   * Create a new mongo user provider.
   *
   * @return \Illuminate\Contracts\Auth\Authenticatable|null
   * @return void
   */
  public function __construct($userModel)
  {
     $this->model = $userModel;
  }
 
  
  /* (non-PHPdoc)
     * @see \Illuminate\Contracts\Auth\UserProvider::retrieveById()
     */
    public function retrieveById($identifier)
    {
        // TODO Auto-generated method stub
        
    }

/* (non-PHPdoc)
     * @see \Illuminate\Contracts\Auth\UserProvider::retrieveByToken()
     */
    public function retrieveByToken($identifier, $token)
    {
        // TODO Auto-generated method stub
        
    }

/* (non-PHPdoc)
     * @see \Illuminate\Contracts\Auth\UserProvider::updateRememberToken()
     */
    public function updateRememberToken(\Illuminate\Contracts\Auth\Authenticatable $user, $token)
    {
        // TODO Auto-generated method stub
        
    }

/* (non-PHPdoc)
     * @see \Illuminate\Contracts\Auth\UserProvider::validateCredentials()
     */
    public function validateCredentials(\Illuminate\Contracts\Auth\Authenticatable $user, array $credentials)
    {
        if(!isset($credentials['password']) || $credentials['password'] == ''){
            return false;
        }
        
        return Hash::check($credentials['password'], $user->{AUTHEN_PASS});
        
    }

/**
     * Rehash the user's password if the hashing work factor has moved on.
     *
     * Required by Illuminate\Contracts\Auth\UserProvider since Laravel 11.
     * Omitting it is not a deprecation: PHP refuses to declare a class that
     * does not implement every method of its interface, so the application
     * fatals the moment Auth resolves this provider.
     *
     * This implementation is deliberately a no-op, and the reason is that this
     * provider has no write path. Every mutating method on it -- retrieveById(),
     * retrieveByToken(), updateRememberToken() -- is an empty stub inherited
     * from upstream; the only method that does real work is
     * retrieveByCredentials(), which reads. Storing a rehashed password would
     * mean reaching into App\Helpers\DB\Models from here and inventing an
     * update path that this class has never had, on a code path nothing in this
     * application currently reaches.
     *
     * Nothing reaches it because the framework calls this from
     * Illuminate\Auth\SessionGuard::attempt(), and this application does not
     * use SessionGuard: config/auth.php makes 'jwt' the default guard, and
     * App\Services\Auth\JwtGuard::attempt() is hand-written and calls
     * validateCredentials() directly. So the method exists to satisfy the
     * contract and to keep the class declarable, and it is inert.
     *
     * If a password-write path is ever added to this provider, this is where
     * the rehash belongs -- do not silently leave it a stub then.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @param  array  $credentials
     * @param  bool  $force
     * @return void
     */
    public function rehashPasswordIfRequired(\Illuminate\Contracts\Auth\Authenticatable $user, array $credentials, bool $force = false)
    {
        // No-op. See the doc block above.
    }

/**
   * Retrieve a user by the given credentials.
   *
   * @param  array  $credentials
   * @return \Illuminate\Contracts\Auth\Authenticatable|null
   */
  public function retrieveByCredentials(array $credentials)
  {
    if (empty($credentials)) {
        return null;
    }
    
    if(isset($credentials['username']) && $credentials['username']!=''){
        $userName = $credentials['username'];
    
        $key = [
            [[AUTHEN_EMAIL, '=', $userName]],
            [[AUTHEN_USERNAME, '=', $userName]],
            [[AUTHEN_PHONE, '=', $userName]],
        ];
    }
    
    $user = $this->model->read($key);
    
    if($user['result'] && isset($user['data'][0])){
        $user = (array)$user['data'][0];
        return new JwtGenericUser($user);
    }else{
        return null;
    }
    
  }
 
  
  
}
