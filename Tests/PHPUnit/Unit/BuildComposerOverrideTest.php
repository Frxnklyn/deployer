<?php
namespace axenox\Deployer\Tests\PHPUnit\Unit;

use axenox\Deployer\Actions\Build;
use exface\Core\CommonLogic\Tasks\GenericTask;
use exface\Core\CommonLogic\Workbench;
use exface\Core\Exceptions\Actions\ActionInputError;
use PHPUnit\Framework\TestCase;

/**
 * Guards complete per-run Composer replacement without changing stored variant data.
 *
 * @author Brooklyn Fränzschky
 */
class BuildComposerOverrideTest extends TestCase
{
    /**
     * Exercises the production resolver without reading model data or running Composer.
     *
     * @param array $parameters
     * @return string
     */
    private function resolve(array $parameters): string
    {
        $action = $this->getMockBuilder(Build::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBuildVariantData', 'getProjectData'])
            ->getMock();
        $action->expects(self::never())->method('getBuildVariantData');
        $action->expects(self::never())->method('getProjectData');
        $task = new GenericTask(new Workbench());
        foreach ($parameters as $name => $value) {
            $task->setParameter($name, $value);
        }
        return (new \ReflectionMethod(Build::class, 'getComposerOverride'))->invoke($action, $task);
    }

    /**
     * Replacement must not retain old dependencies or mutate state across runs.
     *
     * @return void
     */
    public function testOverrideReplacesTheWholeDocumentForOneRun(): void
    {
        $replacement = '{"require":{"example/test":"2.0"}}';
        self::assertSame($replacement, $this->resolve(['composer_json' => $replacement]));
        self::assertSame('{}', $this->resolve(['composer_json' => '{}']));
    }

    /**
     * Invalid explicit overrides fail rather than silently reverting to saved configuration.
     *
     * @return void
     */
    public function testInvalidOverridesFailWithoutExposingTheirContents(): void
    {
        foreach (['', ' ', '0', 'null', '[]', '"secret-value"', '{"token":"secret-value"', false, 0, []] as $value) {
            try {
                $this->resolve(['composer_json' => $value]);
                self::fail('Invalid explicit override must fail.');
            } catch (ActionInputError $error) {
                self::assertStringContainsString('composer_json', $error->getMessage());
                self::assertStringNotContainsString('secret-value', $error->getMessage());
            }
        }
    }

    /**
     * Unrelated task settings must not replace the variant Composer document.
     *
     * @return void
     */
    public function testOtherParametersDoNotOverrideComposerConfiguration(): void
    {
        self::assertSame('{"require":{}}', $this->resolve([
            'composer_json' => '{"require":{}}',
            'composer_timeout' => 1,
            'config_uxon' => '{"composer_json":{}}'
        ]));
    }
}
