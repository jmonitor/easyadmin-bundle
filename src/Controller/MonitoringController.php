<?php

declare(strict_types=1);

namespace Jmonitor\EasyAdminBundle\Controller;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Provider\AdminContextProviderInterface;
use Jmonitor\EasyAdminBundle\Component;
use Jmonitor\EasyAdminBundle\Detection\ComponentRegistry;
use Jmonitor\EasyAdminBundle\Snapshot\SnapshotProvider;
use Jmonitor\EasyAdminBundle\View\ViewFactory;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Twig\Environment;

/**
 * EasyAdmin registers these routes under every dashboard, named "<dashboard>_jmonitor_<action>".
 */
#[AdminRoute('/jmonitor', name: 'jmonitor')]
final class MonitoringController
{
    private const COMPONENT_OPTIONS = ['requirements' => ['component' => 'system|php|symfony|mysql|redis']];

    public function __construct(
        private readonly ComponentRegistry $registry,
        private readonly SnapshotProvider $snapshots,
        private readonly ViewFactory $views,
        private readonly Environment $twig,
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly AdminContextProviderInterface $adminContextProvider,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $role,
        private readonly int $pollingInterval,
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
    public function component(Component $component): Response
    {
        return $this->render($component, fragment: false);
    }

    #[AdminRoute(path: '/{component}/fragment', name: 'fragment', options: self::COMPONENT_OPTIONS)]
    public function fragment(Component $component): Response
    {
        return $this->render($component, fragment: true);
    }

    #[AdminRoute(path: '/{component}/refresh', name: 'refresh', options: self::COMPONENT_OPTIONS + ['methods' => ['POST']])]
    public function refresh(Component $component): Response
    {
        $this->denyAccessUnlessGranted();
        $this->denyUnlessAvailable($component);

        $this->snapshots->refresh($component);

        return new RedirectResponse($this->componentUrl($component));
    }

    private function render(Component $component, bool $fragment): Response
    {
        $this->denyAccessUnlessGranted();
        $this->denyUnlessAvailable($component);

        $snapshot = $this->snapshots->get($component);

        return new Response($this->twig->render(sprintf('@JmonitorEasyAdmin/component/%s.html.twig', $component->value), $this->context($component) + [
            'snapshot' => $snapshot,
            'view' => $this->views->create($component, $snapshot),
            'fragment' => $fragment,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(?Component $current): array
    {
        return [
            'components' => $this->registry->available(),
            'current' => $current,
            'polling_interval' => $this->pollingInterval,
            'routes' => [
                'component' => $this->routeName('component'),
                'fragment' => $this->routeName('fragment'),
                'refresh' => $this->routeName('refresh'),
            ],
        ];
    }

    private function componentUrl(Component $component): string
    {
        return $this->urlGenerator->generate($this->routeName('component'), ['component' => $component->value]);
    }

    private function routeName(string $action): string
    {
        $context = $this->adminContextProvider->getContext() ?? throw new \LogicException('No EasyAdmin context: the monitoring routes must be reached through an EasyAdmin dashboard.');

        return $context->getDashboardRouteName() . '_jmonitor_' . $action;
    }

    private function denyAccessUnlessGranted(): void
    {
        if (!$this->authorizationChecker->isGranted($this->role)) {
            throw new AccessDeniedException(sprintf('The monitoring pages require the "%s" role.', $this->role));
        }
    }

    private function denyUnlessAvailable(Component $component): void
    {
        if (!$this->registry->isAvailable($component)) {
            throw new NotFoundHttpException(sprintf('The "%s" component is not available.', $component->label()));
        }
    }
}
