<?php

namespace lameco\rankroute\tests\integration;

use lameco\rankroute\console\controllers\TextFlowController;

/**
 * The smoke command with its console output captured instead of written to STDOUT.
 */
final class CapturingTextFlowController extends TextFlowController
{
    public string $output = '';

    /**
     * @return int
     */
    public function stdout($string)
    {
        $this->output .= $string;

        return strlen($string);
    }

    /**
     * @return int
     */
    public function stderr($string)
    {
        return $this->stdout($string);
    }

    public function table(array $headers, array $data, array $options = []): void
    {
        foreach ($data as $row) {
            $this->output .= implode(' | ', $row) . "\n";
        }
    }
}
