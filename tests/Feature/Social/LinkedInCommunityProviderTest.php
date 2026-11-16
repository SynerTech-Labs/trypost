<?php

declare(strict_types=1);

use App\Socialite\LinkedInCommunityProvider;
use App\Socialite\LinkedInPageExtendSocialite;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use SocialiteProviders\Manager\SocialiteWasCalled;

test('page-only community authorization requests exactly the configured permissions', function () {
    config([
        'trypost.platforms.linkedin.enabled' => false,
        'trypost.platforms.linkedin-page.enabled' => true,
        'trypost.platforms.linkedin-page.scopes' => [
            'r_basicprofile', 'rw_organization_admin', 'r_organization_social', 'w_organization_social',
        ],
        'services.linkedin-openid.client_id' => 'client-id',
        'services.linkedin-openid.client_secret' => 'client-secret',
        'services.linkedin-openid.redirect' => 'https://example.com/callback',
    ]);
    (new LinkedInPageExtendSocialite)->handle(app(SocialiteWasCalled::class));
    Socialite::forgetDrivers();
    $provider = Socialite::driver('linkedin-openid');

    $url = $provider->stateless()->scopes(config('trypost.platforms.linkedin-page.scopes'))->redirect()->getTargetUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);

    expect($query['scope'])->toBe('r_basicprofile rw_organization_admin r_organization_social w_organization_social');
    expect($query['client_id'])->toBe('client-id');
});

test('community callback reads the basic profile without requesting openid or email and retains refresh credentials', function () {
    config([
        'trypost.platforms.linkedin.enabled' => false,
        'trypost.platforms.linkedin-page.enabled' => true,
        'trypost.platforms.linkedin-page.scopes' => ['r_basicprofile'],
        'services.linkedin-openid.client_id' => 'client-id',
        'services.linkedin-openid.client_secret' => 'client-secret',
        'services.linkedin-openid.redirect' => 'https://example.com/callback',
    ]);
    (new LinkedInPageExtendSocialite)->handle(app(SocialiteWasCalled::class));
    Socialite::forgetDrivers();
    $provider = Socialite::driver('linkedin-openid');
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'access_token' => 'access-token', 'refresh_token' => 'refresh-token',
            'expires_in' => 3600, 'scope' => 'r_basicprofile,w_organization_social',
        ])),
        new Response(200, [], json_encode([
            'id' => 'member-123',
            'firstName' => ['localized' => ['en_US' => 'Jane'], 'preferredLocale' => ['language' => 'en', 'country' => 'US']],
            'lastName' => ['localized' => ['en_US' => 'Doe']],
        ])),
    ]));
    $handler->push(Middleware::history($history));
    $provider->setHttpClient(new Client(['handler' => $handler]));
    $provider->setRequest(Request::create('/callback', 'GET', ['code' => 'authorization-code']));

    $user = $provider->stateless()->user();

    expect($user->getId())->toBe('member-123');
    expect($user->getName())->toBe('Jane Doe');
    expect($user->getEmail())->toBeNull();
    expect($user->token)->toBe('access-token');
    expect($user->refreshToken)->toBe('refresh-token');
    expect($user->expiresIn)->toBe(3600);
    expect($user->approvedScopes)->toBe(['r_basicprofile,w_organization_social']);
    expect($history)->toHaveCount(2);
    expect($history[0]['request']->getUri()->getPath())->toBe('/oauth/v2/accessToken');
    expect($history[1]['request']->getUri()->getPath())->toBe('/v2/me');
    expect($history[1]['request']->getHeaderLine('Authorization'))->toBe('Bearer access-token');
});

test('openid authentication remains available for personal and standard page connections', function (bool $personal, bool $pages, array $scopes) {
    config([
        'trypost.platforms.linkedin.enabled' => $personal,
        'trypost.platforms.linkedin-page.enabled' => $pages,
        'trypost.platforms.linkedin-page.scopes' => $scopes,
        'services.linkedin-openid.client_id' => 'client-id',
        'services.linkedin-openid.client_secret' => 'client-secret',
        'services.linkedin-openid.redirect' => 'https://example.com/callback',
    ]);
    (new LinkedInPageExtendSocialite)->handle(app(SocialiteWasCalled::class));
    Socialite::forgetDrivers();
    $provider = Socialite::driver('linkedin-openid');
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode([
            'sub' => 'personal-123', 'name' => 'Jane Doe', 'given_name' => 'Jane',
            'family_name' => 'Doe', 'email' => 'jane@example.com',
        ])),
    ]));
    $handler->push(Middleware::history($history));
    $provider->setHttpClient(new Client(['handler' => $handler]));

    $user = $provider->userFromToken('personal-token');

    expect($user->getId())->toBe('personal-123');
    expect($user->getEmail())->toBe('jane@example.com');
    expect($provider->getScopes())->toBe(['openid', 'profile', 'email']);
    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getUri()->getPath())->toBe('/v2/userinfo');
})->with([
    'personal only' => [true, false, ['r_basicprofile']],
    'personal and pages' => [true, true, ['r_basicprofile']],
    'standard page only' => [false, true, ['openid', 'profile', 'email']],
    'disabled capabilities' => [false, false, ['r_basicprofile']],
]);

test('community tokens refresh through the existing oauth endpoint', function () {
    $provider = new LinkedInCommunityProvider(Request::create('/'), 'client-id', 'client-secret', 'https://example.com/callback');
    $history = [];
    $handler = HandlerStack::create(new MockHandler([
        new Response(200, [], json_encode(['access_token' => 'renewed-token', 'refresh_token' => 'renewed-refresh', 'expires_in' => 3600])),
    ]));
    $handler->push(Middleware::history($history));
    $provider->setHttpClient(new Client(['handler' => $handler]));

    $token = $provider->refreshToken('original-refresh');

    expect($token->token)->toBe('renewed-token');
    expect($token->refreshToken)->toBe('renewed-refresh');
    expect($history)->toHaveCount(1);
    expect($history[0]['request']->getUri()->getPath())->toBe('/oauth/v2/accessToken');
    parse_str((string) $history[0]['request']->getBody(), $fields);
    expect($fields['grant_type'])->toBe('refresh_token');
    expect($fields['refresh_token'])->toBe('original-refresh');
});
