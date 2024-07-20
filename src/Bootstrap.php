<?php
namespace PixiekatBootstrap;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Symfony\Component\Cache\Adapter\FilesystemTagAwareAdapter;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\RouteCollection;
use Twig\Environment;
use Twig\TwigFunction;
use Twig\Loader\FilesystemLoader;

class Bootstrap {

  public static function getRootPath(): string {
    $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
    $rootDir = dirname(dirname(dirname($reflection->getFileName())));
    return $rootDir;
  }

  public static function getVendorPath(): string {
    $reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
    $vendorDir = dirname(dirname($reflection->getFileName()));
    return $vendorDir;
  }

  /**
   * The constructor.
   */
  public function createApplication(?RouteCollection $routes = null) {    
    $this->loadDotEnv();
    $request = Request::createFromGlobals();
    $context = new RequestContext();
    $context->fromRequest($request);

    $twig = $this->loadTwig();
    $mailer = $this->loadMailer();
    $entityManager = $this->loadDoctrine();

    return new Application($request, $context, $twig, $mailer, $entityManager, $routes);
  }

  /**
   * Load up Doctrine.
   */
  protected function loadDoctrine() {

    $config = ORMSetup::createAttributeMetadataConfiguration(
      paths: array(__DIR__."/src"),
      isDevMode: $_ENV['APP_DEBUG'] ?? false,
    );

    $dsn = $_ENV['DATABASE_URL'] ?? null;
    $dsnParser = new DsnParser();
    $dbParams = $dsnParser->parse($dsn);
    $db = DriverManager::getConnection($dbParams);
    $entityManager = new EntityManager($db, $config);
    return $entityManager;
  }

  /**
   * Loads up the DotEnv files.
   */
  protected function loadDotEnv() {
    $rootPath = self::getRootPath();
    $dotenv = new Dotenv();
    foreach (['.env', '.env.local', '.env.local.php'] as $file) {
      if (file_exists($rootPath.'/'.$file)) {
        $dotenv->load($rootPath.'/'.$file);
      }
    }
    if (!empty($_ENV['APP_ENV'])) {
      $env = $_ENV['APP_ENV'];
      switch ($env) {
        case 'dev':
          error_reporting(E_ALL);
          ini_set('display_errors', 1);
          break;
        case 'test':
          break;
        case 'prod':
          break;
      }

      foreach ([".env.{$env}", ".env.{$env}.local", ".env.{$env}.local.php"] as $file) {
        if (file_exists($rootPath.'/'.$file)) {
          $dotenv->load($rootPath.'/'.$file);
        }
      }
    }
    return $dotenv;
  }

  /**
   * Loads up the Mailer.
   */
  private function loadMailer() {
    $dsn = $_ENV['MAILER_DSN'] ?? null;
    if ($dsn) {
      $transport = Transport::fromDsn($_ENV['MAILER_DSN']);
      $mailer = new Mailer($transport);
      return $mailer;
    }
  }

  /**
   * Loads up Twig.
   */
  private function loadTwig() {
    $rootPath = self::getRootPath();
    $env = $_ENV['APP_ENV'] ?? 'dev';
    $cache_path = $_ENV['CACHE_PATH'] ?? '/var/cache/';
    $twig_path = $_ENV['TWIG_TEMPLATE_PATH'] ?? '/templates';
    $twig_cache = "{$cache_path}{$env}/twig";

    $loader = new FilesystemLoader($rootPath . $twig_path);
    $twig = new Environment($loader, [
      'debug' => ($_ENV['APP_DEBUG'] ?? false),
      'cache' => $rootPath . $twig_cache,
    ]);
    return $twig;
  }
}

class Application {

  /**
   * The cache object.
   * 
   * @var array $cache
   */
  private $cache;

  /**
   * The Doctrine\ORM\EntityManager definition.
   * 
   * @var Doctrine\ORM\EntityManager $entityManager
   */
  private $entityManager;

  /**
   * The Symfony\Component\Routing\RequestContext definition.
   * 
   * @var Symfony\Component\Routing\RequestContext $context
   */
  private $requestContext;

  /**
   * The Symfony\Component\HttpFoundation\Request definition.
   * 
   * @var Symfony\Component\HttpFoundation\Request $request
   */
  private $request;

  /**
   * The Twig\Environment definition.
   * 
   * @var Twig\Environment $twig
   */
  private $twig;

  /**
   * The Symfony\Component\Mailer\Mailer definition.
   * 
   * @var Symfony\Component\Mailer\Mailer $mailer
   */
  private $mailer;

  /**
   * The Symfony\Component\Routing\RouteCollection definition.
   * 
   * @var Symfony\Component\Routing\RouteCollection $routes
   */
  private $routes;

  /**
   * The Routing\Generator\UrlGenerator definition.
   * 
   * @var Routing\Generator\UrlGenerator $urlGenerator
   */
  private $urlGenerator;

  /**
   * The constructor.
   */
  public function __construct(Request $request, RequestContext $requestContext, Environment $twig, Mailer $mailer, EntityManager $entityManager, RouteCollection $routes) {
    $this->request = $request;
    $this->requestContext = $requestContext;
    $this->setRoutes($routes);

    $twig->addGlobal('app', [
      'env' => $_ENV['APP_ENV'] ?? 'dev',
      'debug' => $_ENV['APP_DEBUG'] ?? true,
      'request' => $this->request,
    ]);
    $urlGenerator = $this->getUrlGenerator();
    $twig->addFunction(new TwigFunction('path', function($url) use ($urlGenerator) {
      return $urlGenerator->generate($url);
    }));
    $this->twig = $twig;
    $this->mailer = $mailer;
    $this->entityManager = $entityManager;

    $env = $_ENV['APP_ENV'] ?? 'dev';
    $cache_path = $_ENV['CACHE_PATH'] ?? '/var/cache/';
    $cache_lifespan = $_ENV['CACHE_DEFAULT_LIFESPAN'] ?? 3600;
    $cache_directory = "{$cache_path}/{$env}" ?? '/var/cache';
    $this->cache = [
      'app' => new FilesystemTagAwareAdapter('app', $cache_lifespan, Bootstrap::getRootPath() . $cache_directory),
    ];
  }

  /**
   * Gets a cache.
   */
  public function getCache(string $name): PruneableInterface {
    return $this->cache[$name];
  }

  /**
   * Gets the current request object.
   */
  public function getCurrentRequest(): Request {
    return $this->request;
  }

  /**
   * Gets the Doctrine\ORM\EntityManager object.
   */
  public function getEntityManager(): EntityManager {
    return $this->entityManager;
  }

  /**
   * Gets the mailer object.
   */
  public function getMailer(): Mailer {
    return $this->mailer;
  }

  /**
   * Gets the current request context.
   */
  public function getRequestContext(): RequestContext {
    return $this->requestContext;
  }

  /**
   * Gets the current routes.
   */
  public function getRoutes(): RouteCollection {
    return $this->routes;
  }

  /**
   * Gets the current Twig object.
   */
  public function getTwig(): Twig\Environment {
    return $this->twig;
  }

  /**
   * Gets the current URL generator.
   */
  public function getUrlGenerator(): UrlGenerator {
    if (!$this->getRoutes()) {
      throw new \Exception('Routes not set.');
    }
    if (!$this->getRequestContext()) {
      throw new \Exception('Request context not set.');
    }
    if (!$this->urlGenerator) {
      $this->urlGenerator = new UrlGenerator($this->getRoutes(), $this->getRequestContext());
    }
    return $this->urlGenerator;
  }

  /**
   * Sets the application routes.
   */
  public function setRoutes(RouteCollection $routes): static {
    $this->routes = $routes;

    return $this;
  }

  /**
   * Sets a global variable or Twig.
   */
  public function setTwigGlobal(string $name, mixed $value): static {
    $this->twig->addGlobal($name, $value);

    return $this;
  }
}