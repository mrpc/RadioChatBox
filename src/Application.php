<?php

namespace RadioChatBox;

/**
 * RadioChatBox application kernel.
 *
 * PramnosFramework resolves the per-app Application class from the `namespace`
 * declared in app/app.php (here: RadioChatBox\Application). During the migration
 * bridge this is a thin subclass of the framework kernel — it exists so the
 * framework's console/bootstrap can instantiate the app. Behaviour is added in
 * later phases as RadioChatBox adopts more of the framework.
 */
class Application extends \Pramnos\Application\Application
{
}
