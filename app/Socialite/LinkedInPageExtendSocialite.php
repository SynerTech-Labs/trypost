<?php

declare(strict_types=1);

namespace App\Socialite;

use SocialiteProviders\LinkedIn\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class LinkedInPageExtendSocialite
{
    public function handle(SocialiteWasCalled $socialiteWasCalled): void
    {
        if (! config('trypost.platforms.linkedin.enabled')
            && config('trypost.platforms.linkedin-page.enabled')
            && in_array('r_basicprofile', config('trypost.platforms.linkedin-page.scopes'), true)) {
            $socialiteWasCalled->extendSocialite('linkedin-openid', LinkedInCommunityProvider::class);

            return;
        }

        $socialiteWasCalled->extendSocialite('linkedin-openid', Provider::class);
    }
}
