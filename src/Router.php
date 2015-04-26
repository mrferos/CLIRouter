<?php
namespace CLIRouter;

class Router
{
    /**
     * The regular expression used to parse options
     *
     * @var string
     */
    protected $paramParseRegex = '#' .
                                '(?:(\[))?' . // Detect if a parameter is optional
                                '(?:-(\w)\|)?' . // Match a short tag
                                '--(\w+)(?:(=)(?:\<([^\s]+)\>)?)?' . // Match a parameter
                                '(?(1)(\]))?' . // Detect the end of an optional parameter block
                                '#';

    protected $cmdLineParseRegex = '#((?:-)?-)?(\w+)(?:=(.*))?#';

    /**
     * @var array
     */
    protected $routes = array();

    public function match(array $commandLineParts)
    {
        $command = $commandLineParts[0];

        if (!array_key_exists($command, $this->routes)) {
            return false;
        }

        $params = $this->getParams(
            $this->routes[$command]['opts'],
            array_slice($commandLineParts, 1)
        );
    }

    public function add($route, $callback)
    {
        $command = $this->getCommand($route);
        if (array_key_exists($command, $this->routes)) {
            throw new \RuntimeException('Route previously registered with same command: '. $command);
        }

        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('$callback must be callable');
        }

        $this->routes[$command] = [
            'opts'     => substr($route, strlen($command) + 1),
            'callback' => $callback
        ];
    }

    /**
     * @param $optLine
     * @param $commandParts
     * @return array
     */
    protected function getParams($optLine, array $commandParts)
    {
        $opts = false;

        if (empty($commandParts)) {
            return [];
        }

        $optParts = explode(' ', $optLine);
        if (empty($optParts)) {
            return [];
        }

        foreach ($optParts as $optPart) {
           if (empty($optPart)) {
               continue;
           }

           if (!preg_match($this->paramParseRegex, $optPart, $optParsedParts)) {
               throw new \RuntimeException('Could not parse opt line: ' . $optPart);
           }

            // Setting up some variables that dictate flow control
           $match         = false;
           $required      = empty($optParsedParts[1]) && empty($optParsedParts[6]);
           $valueRequired = array_key_exists(4, $optParsedParts) && $optParsedParts[4] == '=';
           $validation    = array_key_exists(5, $optParsedParts) && $optParsedParts[5];
           $longOption    = $optParsedParts[3];
           $shortOption   = $optParsedParts[2];
           $value         = null;

            // Let's go over the parts of the command (will decrease in size as we match)
           for ($i = 0; $i < count($commandParts); $i++) {
               // $i might be modified in the course of execution - let's keep the original key
               $oKey = $i;
               $commandPart = $commandParts[$i];

               if (!preg_match($this->cmdLineParseRegex, $commandPart, $commandParsedParts)) {
                   continue;
               }

               // What kind of flag are we matching against?
               $flagFound = false;
               $shortOptionPassed = false;
               if ($commandParsedParts[2] == $longOption) {
                   $flagFound = true;
               } elseif ($commandParsedParts[2] == $shortOption) {
                   $shortOptionPassed = true;
                   $flagFound = true;
               }

               if (!$flagFound) {
                   $match = false;
                   break;
               }

               if (!$valueRequired) {
                   $match = true;
                   $value = true;
                   break;
               }

               // If a short option was matched, the next command part is our value
               if ($shortOptionPassed) {
                   // If we find a value, remove it from $commandParts
                   ++$i;
                   if (!array_key_exists($i, $commandParts)) {
                       $match = false;
                       break;
                   }

                   $value = $commandParts[$i];
                   unset($commandParts[$i]);

               // If a long option was match, the value is part of our parsed output
               } else {
                   if (!array_key_exists(3, $commandParsedParts)) {
                       $match = false;
                       break;
                   }

                   $value = $commandParsedParts[3];
               }

               // Check against validation if there is any
               if ($validation && !preg_match('#' . $validation . '#', $value)) {
                   $match = false;
               } else {
                   $match = true;
               }

               // State the match and remove it from $commandParts
               $match = true;
               unset($commandParts[$oKey]);
               // Redo $commandParts to make iteration easier
               $commandParts = array_values($commandParts);
               break;
           }

            // If there is no match, continue. If it's a required param break here.
           if (!$match) {
               var_dump($longOption, $required);
               if ($required) {
                   break;
               }

               continue;
           }

           if (!is_array($opts)) {
               $opts = array();
           }

            // Add it to our param bag
           $opts[!is_null($longOption) ? $longOption : $shortOption] = $value;
        }

        return $opts;
    }

    protected function getCommand($route)
    {
        $command = substr($route, 0, strpos($route, ' '));
        if (empty($command)) {
            throw new \RuntimeException('No command has been supplied');
        }

        if ('-' == substr($command, 0, 1)) {
            throw new \RuntimeException('Routes can not start with options');
        }


        return $command;
    }
}