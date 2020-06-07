<?php

namespace Drutiny;

use Drutiny\Audit\AuditInterface;
use Drutiny\Audit\AuditValidationException;
use Drutiny\Audit\RemediableInterface;
use Drutiny\AuditResponse\AuditResponse;
use Drutiny\Entity\DataBag;
use Drutiny\Policy;
use Drutiny\Policy\DependencyException;
use Drutiny\Sandbox\Sandbox;
use Drutiny\Target\NoSuchPropertyException;
use Drutiny\Target\TargetInterface;
use Drutiny\Upgrade\AuditUpgrade;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 *
 */
abstract class Audit implements AuditInterface
{
    protected $definition;
    protected $logger;
    protected $container;
    protected $target;
    protected $expressionLanguage;
    protected $dataBag;

    final public function __construct(
      ContainerInterface $container,
      TargetInterface $target,
      LoggerInterface $logger,
      ExpressionLanguage $expressionLanguage
      )
    {
      $this->container = $container;
      $this->target = $target;
      $this->logger = $logger;
      $this->definition = new InputDefinition();
      $this->expressionLanguage = $expressionLanguage;
      $this->dataBag = new DataBag([
        'parameters' => new DataBag()
      ]);
      $this->configure();
    }

    public function configure() {}

  /**
   * @return
   */
    abstract public function audit(Sandbox $sandbox);

  /**
   * @param Sandbox $sandbox
   * @return
   * @throws \Drutiny\Audit\AuditValidationException
   */
    final public function execute(Policy $policy, $remediate = false)
    {
        $response = new AuditResponse($policy);
        $this->logger->info('Auditing ' . $policy->name);
        try {
            // Ensure policy dependencies are met.
            foreach ($policy->getDepends() as $dependency) {
                // Throws DependencyException if dependency is not met.
                $dependency->execute($this);
            }

            $input = new ArrayInput($policy->getAllParameters(), $this->definition);
            $this->dataBag->get('parameters')->add($input->getArguments());
            $this->dataBag->add($input->getArguments());

            // Run the audit over the policy.
            $outcome = $this->audit(new Sandbox($this));
            // If the audit wasn't successful and remediation is allowed, then
            // attempt to resolve the issue. TODO: Purge Cache
            if (($this instanceof RemediableInterface) && !$outcome && $remediate) {
                $outcome = $this->remediate(new Sandbox($this));
            }
        }
        catch (DependencyException $e) {
            $outcome = AuditInterface::ERROR;
            $outcome = $e->getDependency()->getFailBehaviour();
            $this->set('exception', $e->getMessage());
            $this->set('exception_type', get_class($e));
        }
        catch (AuditValidationException $e) {
            $outcome = AuditInterface::NOT_APPLICABLE;
            $this->set('exception', $e->getMessage());
            $this->set('exception_type', get_class($e));
            $this->logger->warning($e->getMessage());
        }
        catch (NoSuchPropertyException $e)  {
            $outcome = AuditInterface::NOT_APPLICABLE;
            $this->set('exception', $e->getMessage());
            $this->set('exception_type', get_class($e));
            $this->logger->warning($e->getMessage());
        }
        catch (InvalidArgumentException $e) {
            $outcome = AuditInterface::ERROR;
            $this->set('exception_type', get_class($e));
            $this->logger->warning($e->getMessage());

            $helper = AuditUpgrade::fromAudit($this);
            $helper->addParameterFromException($e);
            $this->set('exception', $helper->getParamUpgradeMessage());
        }
        catch (\Exception $e) {
            $outcome = AuditInterface::ERROR;
            $message = $e->getMessage();
            if ($this->container->get('verbosity')->get() > OutputInterface::VERBOSITY_NORMAL) {
                $message .= PHP_EOL . $e->getTraceAsString();
            }
            $this->set('exception', $message);
            $this->set('exception_type', get_class($e));
            $this->logger->error($message);
        }
        finally {
          // Log the parameters output.
          $tokens = $this->dataBag->export();
          $this->logger->debug("Tokens:\n" . Yaml::dump($tokens, 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
          // $this->logger->debug("Parameters:\n" . Yaml::dump($this->dataBag->get('parameters')->all(), 4, 4, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));
          // Set the response.
          $response->set($outcome, $tokens);
        }

        return $response;
    }

    /**
     * Evaluate an expression using the Symfony ExpressionLanguage engine.
     */
    public function evaluate($expression)
    {
      return $this->expressionLanguage->evaluate($expression, $this->getContexts());
    }

    /**
     * Allow strings to utilise Audit and Target contexts.
     */
    public function interpolate(string $string, array $contexts = []):string
    {
      return $this->_interpolate($string, array_merge($contexts, $this->getContexts()));
    }

    /**
     * Helper function for the public interpolate function.
     */
    private function _interpolate(string $string, iterable $vars, $key_prefix = ''):string
    {
        foreach ($vars as $key => $value) {
          if (is_iterable($value)) {
            $string = $this->_interpolate($string, $value, $key.'.');
          }

          $token = '{'.$key_prefix.$key.'}';
          if (strpos($string, $token) === false) {
            continue;
          }

          $value = (string) $value;
          $string = str_replace($token, $value, $string);
        }
        return $string;
    }

    /**
     * Get all contexts from the Audit class.
     */
    protected function getContexts():array
    {
      $contexts = $this->dataBag->all();
      $contexts['target'] = $this->target;
      foreach ($this->target->getPropertyList() as $key) {
        $contexts[$key] = $this->target->getProperty($key);
      }
      return $contexts;
    }

    /**
     * Set a parameter. Typically provided by a policy.
     */
    public function setParameter(string $name, $value):AuditInterface
    {
      $this->dataBag->get('parameters')->set($name, $value);
      return $this;
    }

    public function getParameter(string $name, $default_value = null)
    {
      return $this->dataBag->get('parameters')->get($name) ?? $default_value;
    }

    /**
     * Set a non-parameterized value such as a token.
     *
     * This function is used to communicate output data computed by the
     * audit class. This is useful for policies to use to contextualize
     * messaging.
     */
    public function set(string $name, $value):AuditInterface
    {
      $this->dataBag->set($name, $value);
      return $this;
    }

    public function get(string $name)
    {
      return $this->dataBag->get($name);
    }

    /**
     * Used to provide target to deprecated Sandbox object.
     * @deprecated
     */
    public function getTarget():TargetInterface
    {
      return $this->target;
    }

    /**
     * Used to provide logger to deprecated Sandbox object.
     * @deprecated
     */
    public function getLogger():LoggerInterface
    {
      return $this->logger;
    }

    /**
     * Set information about a parameter.
     *
     * This is used exclusively when the configure() method is called.
     * This allows the audit to specify and validate inputs from a policy.
     */
    protected function addParameter(string $name, int $mode = null, string $description = '', $default = null):AuditInterface
    {
        if (!isset($this->definition)) {
          $this->definition = new InputDefinition();
        }
        $this->definition->addArgument(new InputArgument($name, $mode, $description, $default));
        return $this;
    }
}
