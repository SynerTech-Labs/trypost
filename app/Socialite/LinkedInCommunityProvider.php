<?php

declare(strict_types=1);

namespace App\Socialite;

use Laravel\Socialite\Two\LinkedInProvider;
use SocialiteProviders\Manager\ConfigTrait;
use SocialiteProviders\Manager\Contracts\OAuth2\ProviderInterface;

class LinkedInCommunityProvider extends LinkedInProvider implements ProviderInterface
{
    use ConfigTrait;

    protected $scopes = [];

    protected function getUserByToken(mixed $token): array
    {
        return $this->getBasicProfile($token);
    }
}
