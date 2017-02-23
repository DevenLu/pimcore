<?php

namespace Pimcore\Bundle\PimcoreAdminBundle\Controller;

use Pimcore\Bundle\PimcoreBundle\Configuration\TemplatePhp;
use Pimcore\Bundle\PimcoreBundle\Templating\Model\ViewModel;
use Pimcore\Config;
use Pimcore\Model\User;
use Pimcore\Tool;
use Pimcore\Tool\Authentication;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class LoginController extends AdminController
{
    /**
     * @Route("/login", name="admin_login")
     * @TemplatePhp()
     */
    public function loginAction(Request $request)
    {
        $user = $this->getUser();
        if ($user instanceof UserInterface) {
            return $this->redirectToRoute('admin_index');
        }

        $view = $this->buildLoginPageViewModel();

        if ($request->get('auth_failed')) {
            $view->error = 'error_auth_failed';
        }
        if ($request->get('session_expired')) {
            $view->error = 'error_session_expired';
        }

        return $view;
    }

    /**
     * Dummy route used to check authentication (see guard)
     *
     * @Route("/login/login", name="admin_login_check")
     */
    public function loginCheckAction()
    {
        // just in case the authenticator didn't redirect
        return new RedirectResponse($this->generateUrl('admin_login'));
    }

    /**
     * @Route("/login/lostpassword", name="admin_login_lostpassword")
     * @TemplatePhp()
     */
    public function lostpasswordAction(Request $request)
    {
        $view = $this->buildLoginPageViewModel();
        $view->success = false;

        // TODO is the error on the view used somewhere?
        if ($request->getMethod() === 'POST' && $username = $request->get("username")) {
            $user = User::getByName($username);

            if (!$user instanceof User) {
                $view->error = "user unknown";
            } else {
                if ($user->isActive()) {
                    if ($user->getEmail()) {
                        $token = Authentication::generateToken($username, $user->getPassword());

                        $loginUrl = $this->generateUrl('admin_login_check', [
                            'username' => $username,
                            'token'    => $token,
                            'reset'    => 'true'
                        ], UrlGeneratorInterface::ABSOLUTE_URL);

                        try {
                            // TODO trigger a symfony event
                            $results = \Pimcore::getEventManager()->trigger("admin.login.login.lostpassword", $this, [
                                "user" => $user,
                                "loginUrl" => $loginUrl
                            ]);

                            if ($results->count() === 0) { // no event has been triggered
                                $mail = Tool::getMail([$user->getEmail()], "Pimcore lost password service");
                                $mail->setIgnoreDebugMode(true);
                                $mail->setBodyText("Login to pimcore and change your password using the following link. This temporary login link will expire in 30 minutes: \r\n\r\n" . $loginUrl);
                                $mail->send();
                            }
                            $view->success = true;
                        } catch (\Exception $e) {
                            $view->error = "could not send email";
                        }
                    } else {
                        $view->error = "user has no email address";
                    }
                } else {
                    $view->error = "user inactive";
                }
            }
        }

        return $view;
    }

    /**
     * @Route("/login/deeplink", name="admin_login_deeplink")
     * @TemplatePhp()
     */
    public function deeplinkAction()
    {
        // check for deeplink
        $queryString = $_SERVER["QUERY_STRING"];

        if (preg_match("/(document|asset|object)_([0-9]+)_([a-z]+)/", $queryString, $deeplink)) {
            if (strpos($queryString, "token")) {
                $deeplink = $deeplink[0];
                $url = $this->generateUrl('admin_login', [
                    'deeplink' => $deeplink
                ]);

                $url .= '&' . $queryString;

                return $this->redirect($url);
            } elseif ($queryString) {
                return new ViewModel([
                    'tab' => $queryString
                ]);
            }
        }
    }

    /**
     * @return ViewModel
     */
    protected function buildLoginPageViewModel()
    {
        $bundleManager = $this->get('pimcore.extension.bundle_manager');

        $view = new ViewModel([
            'config'         => Config::getSystemConfig(),
            'pluginCssPaths' => $bundleManager->getCssPaths()
        ]);

        return $view;
    }
}
