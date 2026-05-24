<?php

namespace Throttle;

use Silex\Application;

class Sharing
{
    private static function getSafeReturnPath(Application $app, ?string $return): string
    {
        if (is_string($return) && $return !== '' && str_starts_with($return, '/') && !str_starts_with($return, '//')) {
            $parts = parse_url($return);
            if ($parts !== false && !isset($parts['scheme']) && !isset($parts['host'])) {
                return $return;
            }
        }

        return $app['url_generator']->generate('share');
    }

    public function share(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $sharing = $app['db']->executeQuery(
            'SELECT share.user AS id, server_owner.name, NULL AS avatar, steam.identifier AS steam_id, discord.identifier AS discord_id, email.identifier AS email, accepted
             FROM share
             LEFT JOIN server_owner ON share.user = server_owner.id
             LEFT JOIN external_account AS steam ON steam.user_id = share.user AND steam.kind = ?
             LEFT JOIN external_account AS discord ON discord.user_id = share.user AND discord.kind = ?
             LEFT JOIN external_account AS email ON email.user_id = share.user AND email.kind = ?
             WHERE share.owner = ?
             ORDER BY accepted IS NULL DESC, accepted DESC',
            array('steam', 'discord', 'email', $app['user']['id'])
        )->fetchAll();
        $shared = $app['db']->executeQuery(
            'SELECT share.owner AS id, server_owner.name, NULL AS avatar, steam.identifier AS steam_id, discord.identifier AS discord_id, email.identifier AS email, accepted
             FROM share
             LEFT JOIN server_owner ON share.owner = server_owner.id
             LEFT JOIN external_account AS steam ON steam.user_id = share.owner AND steam.kind = ?
             LEFT JOIN external_account AS discord ON discord.user_id = share.owner AND discord.kind = ?
             LEFT JOIN external_account AS email ON email.user_id = share.owner AND email.kind = ?
             WHERE share.user = ?
             ORDER BY accepted IS NULL DESC, accepted DESC',
            array('steam', 'discord', 'email', $app['user']['id'])
        )->fetchAll();

        return $app['twig']->render('share.html.twig', [
            'sharing' => $sharing,
            'shared' => $shared,
        ]);
    }

    public function invite(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $settings = $app['config']['upload-settings'] ?? [];

        return $app['twig']->render('invite.html.twig', [
            'share_invite_methods' => [
                'user_id' => true,
                'email' => !empty($settings['auth_enable_email_login_link']) || !empty($settings['auth_enable_password_login']) || !empty($settings['auth_enable_password_registration']) || !empty($settings['auth_enable_password_reset']),
                'steam' => !empty($settings['auth_enable_steam']),
                'discord' => !empty($settings['auth_enable_discord']),
            ],
        ]);
    }

    public function invite_post(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $target = $this->extractInviteTarget($app);
        if ($target === null) {
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        $user = $this->resolveInviteTargetUserId($app, $target['type'], $target['value']);
        if ($user === null) {
            $app['session']->getFlashBag()->add('error_share_invite', 'Invalid or unknown user ID, email, SteamID64, or Discord ID.');
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        if ($user === $app['user']['id']) {
            $app['session']->getFlashBag()->add('error_share_invite', 'You already have full access to your own reports');
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        $query = $app['db']->executeQuery('SELECT accepted FROM share WHERE owner = ? AND user = ?', array($app['user']['id'], $user))->fetch();
        if ($query !== false) {
            if ($query['accepted'] !== null) {
                $app['session']->getFlashBag()->add('error_share_invite', 'You have already granted that user access');
            } else {
                $app['session']->getFlashBag()->add('error_share_invite', 'You have already invited that user, but they have not accepted yet');
            }
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        $app['db']->executeUpdate('INSERT INTO share (owner, user) VALUES (?, ?)', array($app['user']['id'], $user));

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null));

        return $app->redirect($return);
    }

    /**
     * @return array{type: string, value: string}|null
     */
    private function extractInviteTarget(Application $app): ?array
    {
        $fields = [
            'user_id' => trim((string) $app['request']->get('user_id', '')),
            'email' => mb_strtolower(trim((string) $app['request']->get('email', ''))),
            'steam' => trim((string) $app['request']->get('steam_id', '')),
            'discord' => trim((string) $app['request']->get('discord_id', '')),
        ];

        $filled = array_filter($fields, static fn (string $value): bool => $value !== '');
        if ($filled === []) {
            $legacy = trim((string) $app['request']->get('user', ''));
            if ($legacy === '') {
                $app['session']->getFlashBag()->add('error_share_invite', 'Enter a user ID, email, SteamID64, or Discord ID.');
                return null;
            }

            if (filter_var($legacy, FILTER_VALIDATE_EMAIL)) {
                return ['type' => 'email', 'value' => mb_strtolower($legacy)];
            }

            if (preg_match('/^steam:(\d{15,20})$/', $legacy, $matches) === 1) {
                return ['type' => 'steam', 'value' => $matches[1]];
            }

            if (ctype_digit($legacy) && strlen($legacy) >= 15) {
                return ['type' => 'steam', 'value' => $legacy];
            }

            return ['type' => 'user_id', 'value' => $legacy];
        }

        if (count($filled) > 1) {
            $app['session']->getFlashBag()->add('error_share_invite', 'Fill only one invite field at a time.');

            return null;
        }

        $type = array_key_first($filled);
        return ['type' => (string) $type, 'value' => (string) $filled[$type]];
    }

    private function resolveInviteTargetUserId(Application $app, string $type, string $target): ?int
    {
        if ($target === '') {
            return null;
        }

        if ($type === 'email') {
            if (!filter_var($target, FILTER_VALIDATE_EMAIL)) {
                return null;
            }

            return $this->findUserIdByExternalAccount($app, 'email', mb_strtolower($target));
        }

        if ($type === 'steam') {
            if (preg_match('/^steam:(\d{15,20})$/', $target, $matches) === 1) {
                return $this->findUserIdByExternalAccount($app, 'steam', $matches[1]);
            }
            if (!ctype_digit($target) || strlen($target) < 15) {
                return null;
            }

            return $this->findUserIdByExternalAccount($app, 'steam', $target);
        }

        if ($type === 'discord') {
            return $this->findUserIdByExternalAccount($app, 'discord', $target);
        }

        if (!ctype_digit($target) || strlen($target) >= 15) {
            return null;
        }

        $user = (int) $target;
        if ($user <= 0) {
            return null;
        }

        $exists = $app['db']->executeQuery('SELECT 1 FROM user WHERE id = ?', array($user))->fetchColumn(0);

        return $exists === false ? null : $user;
    }

    private function findUserIdByExternalAccount(Application $app, string $kind, string $identifier): ?int
    {
        $user = $app['db']->executeQuery(
            'SELECT user_id FROM external_account WHERE kind = ? AND identifier = ? LIMIT 1',
            array($kind, $identifier)
        )->fetchColumn(0);

        return $user === false ? null : (int) $user;
    }

    public function accept(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $owner = $app['request']->get('owner', null);
        if ($owner === null || !ctype_digit($owner) || (int) $owner <= 0) {
            throw new \Exception('Missing or invalid target');
        }

        $app['db']->executeUpdate('UPDATE share SET accepted = NOW() WHERE owner = ? AND user = ?', array((int) $owner, $app['user']['id']));

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null));

        return $app->redirect($return);
    }

    public function revoke(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $user = $app['request']->get('user', null);
        $owner = $app['request']->get('owner', null);
        if ($user === $owner || ($user !== null && $owner !== null)) {
            throw new \Exception('Missing or multiple targets');
        }

        if ($user !== null) {
            if (!ctype_digit($user) || (int) $user <= 0) {
                throw new \Exception('Invalid target');
            }

            $app['db']->executeUpdate('DELETE FROM share WHERE owner = ? AND user = ?', array($app['user']['id'], (int) $user));
        } else if ($owner !== null) {
            if (!ctype_digit($owner) || (int) $owner <= 0) {
                throw new \Exception('Invalid target');
            }

            $app['db']->executeUpdate('DELETE FROM share WHERE owner = ? AND user = ?', array((int) $owner, $app['user']['id']));
        }

        $return = self::getSafeReturnPath($app, $app['request']->get('return', null));

        return $app->redirect($return);
    }
}

