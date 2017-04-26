<?php

namespace Drutiny\Driver;

/**
 *
 */
trait DrushTrait {

  protected $drushOptions = [];

  /**
   * Converts into method into a Drush command.
   */
  public function __call($method, $args) {
    // Convert method from camelCase to Drush hyphen based method naming.
    // E.g. PmInfo will become pm-info.
    preg_match_all('/((?:^|[A-Z])[a-z]+)/', $method, $matches);
    $method = implode('-', array_map('strtolower', $matches[0]));
    $output = $this->runCommand($method, $args);

    if (in_array('--format=json', $this->drushOptions)) {
      if (!$json = json_decode($output, TRUE)) {
        throw new DrushFormatException("Cannot parse json output from drush: $output", $output);
      }
    }

    // Reset drush options.
    $this->drushOptions = [];

    return $json;
  }

  public function sqlq($sql) {
    $args = ['"' . $sql . '"'];
    return trim($this->__call('sqlq', $args));
  }

  /**
   *
   */
  public function runCommand($method, $args) {
    return $this->sandbox()->exec('drush @options @method @args', [
      '@method' => $method,
      '@args' => implode(' ', $args),
      '@options' => implode(' ', $this->drushOptions),
    ]);
  }

  /**
   *
   */
  public function setDrushOptions(array $options) {
    foreach ($options as $key => $value) {
      if (is_int($key)) {
        continue;
      }
      if (strlen($key) == 1) {
        $option = '-' . $key;
        if (!empty($value)) {
          $option .= ' ' . $value;
        }
      }
      else {
        $option = '--' . $key;
        if (!empty($value)) {
          $option .= '=' . $value;
        }
      }
      $this->drushOptions[] = $option;
    }
    return $this;
  }

  /**
   * This function takes PHP in this execution scope (Closure) and executes it
   * against the Drupal target using Drush php-script.
   */
  public function evaluate(\Closure $callback, Array $args) {
    $args = array_values($args);
    $func = new \ReflectionFunction($callback);
    $filename = $func->getFileName();
    $start_line = $func->getStartLine() - 1; // it's actually - 1, otherwise you wont get the function() block
    $end_line = $func->getEndLine();
    $length = $end_line - $start_line;

    $source = file($filename);
    $body = array_slice($source, $start_line, $length);

    $col = strpos($body[0], 'function');
    $body[0] = substr($body[0], $col);

    $last = count($body) - 1;
    $col = strpos($body[$last], '}') + 1;
    $body[$last] = substr($body[$last], 0, $col);

    $code = [];
    $code[] = '<?php ';
    $calling_args = [];
    foreach ($func->getParameters() as $i => $param) {
      $code[] = '$' . $param->name . ' = ' . var_export($args[$i], TRUE) . ';';
      $calling_args[] = '$' . $param->name;
    }

    $code[] = '$evaluation = ' . implode("", $body) . ';';
    $code[] = '$response = $evaluation(' . implode(', ', $calling_args) . ');';
    $code[] = 'echo json_encode($response);';

    $transfer = base64_encode(implode(PHP_EOL, $code));

    $tmpfile = trim($this->sandbox()->exec('mktemp'));
    $this->sandbox()->exec('echo ' . $transfer . ' | base64 --decode > ' . $tmpfile);
    $this->sandbox()->exec('cat ' . $tmpfile);

    $return = $this->phpScript($tmpfile);

    // cleanup.
    $this->sandbox()->exec('rm ' . $tmpfile);

    return json_decode($return, TRUE);
  }

}
