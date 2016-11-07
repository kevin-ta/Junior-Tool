<?php

/*
 * This file is part of the Sonata Project package.
 *
 * (c) Thomas Rabaix <thomas.rabaix@sonata-project.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Application\Sonata\UserBundle\Controller;

use FOS\UserBundle\Model\UserInterface;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Application\Sonata\UserBundle\Entity\Skill;
use Ferus\FairPayApi\FairPay;
use Ferus\FairPayApi\Exception\ApiErrorException;

/**
 * Class SonataRegistrationController.
 *
 * This class is inspired from the FOS RegistrationController
 *
 *
 * @author Hugo Briand <briand@ekino.com>
 */
class RegistrationFOSUser1Controller extends Controller
{
    /**
     * @return RedirectResponse
     */
    public function registerAction()
    {
        $user = $this->getUser();

        if ($user instanceof UserInterface) {
            $this->get('session')->getFlashBag()->set('sonata_user_error', 'sonata_user_already_authenticated');

            return $this->redirect($this->generateUrl('sonata_user_profile_show'));
        }

        $form = $this->get('sonata.user.registration.form');
        $formHandler = $this->get('sonata.user.registration.form.handler');
        $confirmationEnabled = $this->container->getParameter('fos_user.registration.confirmation.enabled');
        
        $process = $formHandler->process($confirmationEnabled);
        if ($process) {
            $user = $form->getData();

            $em = $this->container->get('doctrine')->getManager();
            if($form->get('tags')->getData() !== null)
            {
                $tagsArray = explode(',', $form->get('tags')->getData());
                foreach ($tagsArray as $tag)
                {
                    $skill = $em->getRepository('ApplicationSonataUserBundle:Skill')->findOneByName($tag);
                    if($skill === null)
                    {
                        $skill = new Skill();
                        $skill->setName($tag);
                        $em->persist($skill);
                    }
                    $user->addSkill($skill);
                }
            }
            
            try{
                $fairpay = new FairPay();
                preg_match('/^([A-Za-z]+).([A-Za-z]+)@edu\.esiee\.fr$/', $user->getEmail(), $str);
                $data = $fairpay->getStudent($str[1]." ".$str[2]);
                $user->setYear($data->class);
                $user->setFirstname($data->first_name);
                $user->setLastname($data->last_name);
            }
            catch(ApiErrorException $e){}

            $em->persist($user);
            $em->flush();

            $authUser = false;
            if ($confirmationEnabled) {
                $this->get('session')->set('fos_user_send_confirmation_email/email', $user->getEmail());
                $url = $this->generateUrl('fos_user_registration_check_email');
            } else {
                $authUser = true;
                $route = $this->get('session')->get('sonata_basket_delivery_redirect');

                if (null !== $route) {
                    $this->get('session')->remove('sonata_basket_delivery_redirect');
                    $url = $this->generateUrl($route);
                } else {
                    $url = $this->get('session')->get('sonata_user_redirect_url');
                }

                if (null === $route) {
                    $url = $this->generateUrl('sonata_user_profile_show');
                }
            }

            $this->setFlash('fos_user_success', 'registration.flash.user_created');

            $response = new RedirectResponse($url);

            if ($authUser) {
                $this->authenticateUser($user, $response);
            }

            return $response;
        }

        $this->get('session')->set('sonata_user_redirect_url', $this->get('request')->headers->get('referer'));

        return $this->render('FOSUserBundle:Registration:register.html.'.$this->getEngine(), array(
            'form' => $form->createView(),
            'base_template' => $this->get('sonata.admin.pool')->getTemplate('layout'),
        ));
    }

    /**
     * Tell the user to check his email provider.
     *
     * @return Response
     *
     * @throws NotFoundHttpException
     */
    public function checkEmailAction()
    {
        $email = $this->get('session')->get('fos_user_send_confirmation_email/email');
        $this->get('session')->remove('fos_user_send_confirmation_email/email');
        $user = $this->get('fos_user.user_manager')->findUserByEmail($email);

        if (null === $user) {
            throw new NotFoundHttpException(sprintf('The user with email "%s" does not exist', $email));
        }

        return $this->render('FOSUserBundle:Registration:checkEmail.html.'.$this->getEngine(), array(
            'user' => $user,
        ));
    }

    /**
     * Receive the confirmation token from user email provider, login the user.
     *
     * @param string $token
     *
     * @return RedirectResponse
     *
     * @throws NotFoundHttpException
     */
    public function confirmAction($token)
    {
        $user = $this->get('fos_user.user_manager')->findUserByConfirmationToken($token);

        if (null === $user) {
            throw new NotFoundHttpException(sprintf('The user with confirmation token "%s" does not exist', $token));
        }

        $user->setConfirmationToken(null);
        $user->setEnabled(true);
        $user->setLastLogin(new \DateTime());

        $this->get('fos_user.user_manager')->updateUser($user);
        if ($redirectRoute = $this->container->getParameter('sonata.user.register.confirm.redirect_route')) {
            $response = $this->redirect($this->generateUrl($redirectRoute, $this->container->getParameter('sonata.user.register.confirm.redirect_route_params')));
        } else {
            $response = $this->redirect($this->generateUrl('fos_user_registration_confirmed'));
        }

        $this->authenticateUser($user, $response);

        return $response;
    }

    /**
     * Tell the user his account is now confirmed.
     *
     * @return Response
     *
     * @throws AccessDeniedException
     */
    public function confirmedAction()
    {
        $user = $this->getUser();
        if (!is_object($user) || !$user instanceof UserInterface) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        return $this->render('FOSUserBundle:Registration:confirmed.html.'.$this->getEngine(), array(
            'user' => $user,
        ));
    }

    /**
     * Authenticate a user with Symfony Security.
     *
     * @param UserInterface $user
     * @param Response      $response
     */
    protected function authenticateUser(UserInterface $user, Response $response)
    {
        try {
            $this->get('fos_user.security.login_manager')->loginUser(
                $this->container->getParameter('fos_user.firewall_name'),
                $user,
                $response);
        } catch (AccountStatusException $ex) {
            // We simply do not authenticate users which do not pass the user
            // checker (not enabled, expired, etc.).
        }
    }

    /**
     * @param string $action
     * @param string $value
     */
    protected function setFlash($action, $value)
    {
        $this->get('session')->getFlashBag()->set($action, $value);
    }

    /**
     * @return string
     */
    protected function getEngine()
    {
        return $this->container->getParameter('fos_user.template.engine');
    }
}
