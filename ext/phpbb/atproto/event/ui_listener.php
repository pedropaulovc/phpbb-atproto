<?php

declare(strict_types=1);

namespace phpbb\atproto\event;

use phpbb\controller\helper;
use phpbb\language\language;
use phpbb\template\template;
use phpbb\user;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Event listener for AT Protocol UI elements.
 */
class ui_listener implements EventSubscriberInterface
{
    private helper $helper;
    private language $language;
    private template $template;
    private user $user;

    public function __construct(
        helper $helper,
        language $language,
        template $template,
        user $user
    ) {
        $this->helper = $helper;
        $this->language = $language;
        $this->template = $template;
        $this->user = $user;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'core.user_setup' => 'onUserSetup',
            'core.page_header_after' => 'onPageHeaderAfter',
        ];
    }

    /**
     * Load language file during user setup.
     *
     * @param Event $event Event object
     */
    public function onUserSetup(Event $event): void
    {
        $langSetExt = $event['lang_set_ext'] ?? [];
        $langSetExt[] = [
            'ext_name' => 'phpbb/atproto',
            'lang_set' => 'common',
        ];
        $event['lang_set_ext'] = $langSetExt;
    }

    /**
     * Set template variables after page header.
     *
     * @param Event $event Event object
     */
    public function onPageHeaderAfter(Event $event): void
    {
        // Set the login URL
        try {
            $loginUrl = $this->helper->route('phpbb_atproto_oauth_start');
        } catch (\Exception $e) {
            $loginUrl = '';
        }

        $this->template->assign_vars([
            'U_ATPROTO_LOGIN' => $loginUrl,
            'ATPROTO_DID' => '', // Will be populated when user has linked account
            'ATPROTO_HANDLE' => '',
        ]);
    }
}
