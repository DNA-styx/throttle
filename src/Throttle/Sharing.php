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

        $sharing = $app['db']->executeQuery('SELECT share.user AS id, server_owner.name, NULL AS avatar, accepted FROM share LEFT JOIN server_owner ON share.user = server_owner.id WHERE share.owner = ? ORDER BY accepted IS NULL DESC, accepted DESC', array($app['user']['id']))->fetchAll();
        $shared = $app['db']->executeQuery('SELECT share.owner AS id, server_owner.name, NULL AS avatar, accepted FROM share LEFT JOIN server_owner ON share.owner = server_owner.id WHERE share.user = ? ORDER BY accepted IS NULL DESC, accepted DESC', array($app['user']['id']))->fetchAll();

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

        return $app['twig']->render('invite.html.twig');
    }

    public function invite_post(Application $app)
    {
        if (!$app['user']) {
            $app->abort(401);
        }

        $user = $app['request']->get('user', null);
        if ($user === null) {
            $app['session']->getFlashBag()->add('error_share_invite', 'Missing user ID');
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        if (!ctype_digit($user) || (int) $user <= 0) {
            $app['session']->getFlashBag()->add('error_share_invite', 'Invalid user ID');
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        $user = (int) $user;

        if ($user === $app['user']['id']) {
            $app['session']->getFlashBag()->add('error_share_invite', 'You already have full access to your own reports');
            return $app->redirect($app['url_generator']->generate('share_invite'));
        }

        $exists = $app['db']->executeQuery('SELECT 1 FROM user WHERE id = ?', array($user))->fetchColumn(0);
        if ($exists === false) {
            $app['session']->getFlashBag()->add('error_share_invite', 'Unknown user ID');
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

