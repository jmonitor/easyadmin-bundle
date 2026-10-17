<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Detection\Instance;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\View\ViewFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * EasyAdmin registers these routes under every dashboard, named "<dashboard>_jmonitor_<action>".
 */
#[AdminRoute('/jmonitor', name: 'jmonitor')]
final class MonitoringController
{
    private const COMPONENT_OPTIONS = ['requirements' => ['component' => 'system|php|frankenphp|symfony|mysql|postgresql|redis']];
    public const REFRESH_TOKEN_ID = 'jmonitor_refresh';

    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly SnapshotProvider $snapshots,
        private readonly ViewFactory $views,
        private readonly Environment $twig,
        private readonly AdminContextProviderInterface $adminContextProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly int $pollingInterval,
        private readonly ?CsrfTokenManagerInterface $csrfTokenManager = null,
        private readonly ?string $permission = null,
        private readonly ?AuthorizationCheckerInterface $authorizationChecker = null,
    ) {}

    #[AdminRoute(name: 'index')]
    public function index(): Response
    {
        $this->denyAccessUnlessGranted();
        $available = $this->registry->available();

        if ($available === []) {
            return new Response($this->twig->render('@JmonitorEasyAdmin/no_component.html.twig', $this->context(null)));
        }

        return new RedirectResponse($this->componentUrl($available[0]));
    }

    #[AdminRoute(path: '/{component}', name: 'component', options: self::COMPONENT_OPTIONS)]
    public function component(Request $request, Component $component): Response
    {
        $this->denyAccessUnlessGranted();

        return $this->render($this->instanceOr404($component, $request), fragment: false);
    }

    #[AdminRoute(path: '/{component}/fragment', name: 'fragment', options: self::COMPONENT_OPTIONS)]
    public function fragment(Request $request, Component $component): Response
    {
        $this->denyAccessUnlessGranted();

        return $this->render($this->instanceOr404($component, $request), fragment: true);
    }

    #[AdminRoute(path: '/{component}/refresh', name: 'refresh', options: self::COMPONENT_OPTIONS + ['methods' => ['POST']])]
    public function refresh(Request $request, Component $component): Response
    {
        $this->denyAccessUnlessGranted();
        $instance = $this->instanceOr404($component, $request);

        if ($this->csrfTokenManager !== null && !$this->csrfTokenManager->isTokenValid(new CsrfToken(self::REFRESH_TOKEN_ID, $request->request->getString('_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $this->snapshots->refresh($instance);

        // Alone, the instance is the one the bare URL shows.
        $connection = count($this->registry->instancesFor($component)) > 1 ? $instance->id : null;

        return new RedirectResponse($this->componentUrl($component, $connection));
    }

    /**
     * The HTTP exception answers 403 even outside a firewall; inside one, the firewall finds
     * Security's exception behind it and applies its access denied handling.
     */
    private function denyAccessUnlessGranted(): void
    {
        if ($this->permission === null) {
            return;
        }

        if ($this->permission === '') {
            throw new \LogicException('The "permission" option of jmonitor_easyadmin resolved to an empty string: give it a role or remove it.');
        }

        if (!$this->authorizationChecker?->isGranted($this->permission)) {
            $message = sprintf('The monitoring pages need the "%s" permission.', $this->permission);

            throw new AccessDeniedHttpException($message, new AccessDeniedException($message));
        }
    }

    private function render(Instance $instance, bool $fragment): Response
    {
        $component = $instance->component;
        $snapshot = $this->snapshots->get($instance);

        return new Response($this->twig->render(sprintf('@JmonitorEasyAdmin/component/%s.html.twig', $component->value), $this->context($component, $instance) + [
            'snapshot' => $snapshot,
            'view' => $this->views->create($component, $snapshot),
            'fragment' => $fragment,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(?Component $current, ?Instance $instance = null): array
    {
        return [
            'components' => $this->registry->available(),
            'current' => $current,
            'instances' => $current === null ? [] : $this->registry->instancesFor($current),
            'instance' => $instance,
            'polling_interval' => $this->pollingInterval,
            'routes' => [
                'index' => $this->routeName('index'),
                'component' => $this->routeName('component'),
                'fragment' => $this->routeName('fragment'),
                'refresh' => $this->routeName('refresh'),
            ],
        ];
    }

    /**
     * The generator leaves out a null "connection", so the implicit instance keeps the bare URL.
     */
    private function componentUrl(Component $component, ?string $connection = null): string
    {
        return $this->urlGenerator->generate($this->routeName('component'), ['component' => $component->value, 'connection' => $connection]);
    }

    private function routeName(string $action): string
    {
        $context = $this->adminContextProvider->getContext() ?? throw new \LogicException('No EasyAdmin context: the monitoring routes must be reached through an EasyAdmin dashboard.');

        return $context->getDashboardRouteName() . '_jmonitor_' . $action;
    }

    private function instanceOr404(Component $component, Request $request): Instance
    {
        if (!$this->registry->isAvailable($component)) {
            throw new NotFoundHttpException(sprintf('The "%s" component is not available.', $component->label()));
        }

        $id = $request->query->getString('connection');
        $id = $id === '' ? null : $id;

        return $this->registry->instance($component, $id) ?? throw new NotFoundHttpException(sprintf('The "%s" component has no "%s" connection.', $component->label(), $id));
    }
}
