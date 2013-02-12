<?php

namespace EE\TestsGeneratorBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

class TestsCommand extends ContainerAwareCommand
{

    private $filesystem;
    private $templateDir;

    protected function configure()
    {
        $this
            ->setName('ee:test')
            ->setDescription('EE Test Generator')
            ->addArgument('bundle', InputArgument::REQUIRED, 'Bundle name')
            ->addOption(
            'exclude',
            null,
            InputOption::VALUE_OPTIONAL | InputArgument::IS_ARRAY,
            'Exclude folders',
            array( 'Tests', 'Entity', 'DependencyInjection', 'DataFixtures', 'Form', 'Security' )
        );
    }

    protected function render($skeletonDir, $template, $parameters)
    {
        $twig = new \Twig_Environment( new \Twig_Loader_Filesystem( $skeletonDir ), array(
            'debug'            => true,
            'cache'            => false,
            'strict_variables' => true,
            'autoescape'       => false,
        ) );

        return $twig->render($template, $parameters);
    }

    protected function renderFile($skeletonDir, $template, $target, $parameters)
    {
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }

        return file_put_contents($target, $this->render($skeletonDir, $template, $parameters));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        require_once 'PHPUnit/Autoload.php';

        $this->filesystem  = $this->getContainer()->get('filesystem');
        $this->templateDir = __DIR__ . '/../Resources/Template/';
        $bundle            = $this->validateBundleName($input->getArgument('bundle'));
        $bundle            = $this->getContainer()->get('kernel')->getBundle($bundle);
        $dialog            = $this->getDialogHelper();

        $finder = new Finder();
        $dirs   = array();
        //$finder->files()->in($bundle->getPath())->name('*.php')->exclude(array('Tests', 'DependencyInjection', 'DataFixtures', 'Flickr', 'Security', 'Validator', 'Services', 'Twig', 'Command', 'Facebook', 'Repository', 'Form'));
        foreach ($input->getOption('exclude') as $dir) {
            $dirs[] = $dir;
        }
        $finder->files()->in($bundle->getPath())->name('*.php')->exclude($dirs);
        $bundleTestPath = $bundle->getPath() . '/Tests/';

        $itemIndex = 0;
        foreach ($finder as $file) {
            $fileTestExist   = false;
            $fileTestContent = '';
            //katalog
            if (!$this->filesystem->exists($bundleTestPath . $file->getRelativePath())) {
                $this->filesystem->mkdir($bundleTestPath . $file->getRelativePath());
            }

            list( $fileClassNamespace, $fileClass ) = $this->getClassFromFile($file->getRealpath());
            $infoClass = new \ReflectionClass( $fileClassNamespace . '\\' . $fileClass );

            if (true === $infoClass->isAbstract() || true === $infoClass->isInterface()) {
                continue;
            }

            //plik
            $testClassName = pathinfo($file->getRealpath())['filename'] . 'Test';
            $fileTestPath  = $bundleTestPath . $file->getRelativePath() . '/' . $testClassName . '.php';
            if (0 === strlen(pathinfo($file->getRelativePath())['filename'])) {
                $testClassNamespace = $bundle->getNamespace() . '\Tests';
            } else {
                $testClassNamespace = $bundle->getNamespace() . '\Tests\\' . pathinfo(
                    $file->getRelativePath()
                )['filename'];
            }

            $methodsContent = Array();
            //metody w klasie
            foreach ($infoClass->getMethods() as $method) {
                if (!$method->isConstructor() && !$method->isDestructor() && !$method->isAbstract(
                ) && $method->class === $infoClass->getName()
                ) {
                    $methodName = $method->getName();
                    //argumenty funkcji
                    $methodParameters = Array();

                    //$method->getDocComment()

                    foreach ($method->getParameters() as $parameter) {

                        //by reference
                        if (true === $parameter->isPassedByReference()) {
                            $methodParameters[$parameter->getName()] = '&$' . $parameter->getName();
                        } else {
                            $methodParameters[$parameter->getName()] = '$' . $parameter->getName();
                        }

                        if ($this->ResolveParameterClassName($parameter)) {
                            $methodParameters[$parameter->getName()] .= ' ' . $this->ResolveParameterClassName(
                                $parameter
                            ) . ' ';
                        }

                        if (true === $parameter->isDefaultValueAvailable()) {
                            switch (gettype($parameter->getDefaultValue())) {
                                case 'NULL':
                                    $methodParameters[$parameter->getName()] .= ' = null';
                                    break;
                                case 'string':
                                    if (0 === strlen($parameter->getDefaultValue())) {
                                        $methodParameters[$parameter->getName()] .= ' = \'\'';
                                    } else {
                                        $methodParameters[$parameter->getName()] .= ' = ' . $parameter->getDefaultValue(
                                        );
                                    }
                                    break;
                                case 'integer':
                                default:
                                    $methodParameters[$parameter->getName()] .= ' = ' . $parameter->getDefaultValue();
                                    break;
                            }
                        }
                    }
                    //parametry z domyslnymi wartosciami
                    $methodParameters = implode(', ', $methodParameters);

                    $methodsContent['test' . ucfirst($methodName)] = $this->render(
                        $this->templateDir,
                        'method.php.twig',
                        array(
                            'fileClassNamespace' => $fileClassNamespace,
                            'fileClass'          => $fileClass,
                            'methodName'         => $methodName,
                            'ucfirstMethodName'  => ucfirst($methodName),
                            'methodParameters'   => $methodParameters
                        )
                    );
                }
            }

            //jesli nie ma metod to przeskakujemy do nastepnego plik
            if (0 === count($methodsContent)) {
                continue;
            }

            //czy istnienie juz plik z testem
            if ($this->filesystem->exists($fileTestPath)) {
                $fileTestExist = true;
                list( $fileTestClassNamespace, $fileTestClass ) = $this->getClassFromFile($fileTestPath);
                include_once( $fileTestPath );
                $reflectedTest = new \ReflectionClass( $fileTestClassNamespace . '\\' . $fileTestClass );
                $testClassSrc  = file($reflectedTest->getFilename());

                if ($reflectedTest->getNamespaceName() !== $testClassNamespace) {
                    if (!$dialog->askConfirmation(
                        $output,
                        $dialog->getQuestion('Namespace in ' . $fileTestPath . ' is bad, overwrite?', 'yes', '?'),
                        true
                    )
                    ) {
                        $output->writeln('<error>Pomijanie pliku ' . $file->getRealpath() . '</error>');
                        continue;
                    }

                    $src[key(
                        preg_grep('/namespace.' . addslashes($reflectedTest->getNamespaceName()) . ';/', $testClassSrc)
                    )] = 'namespace ' . $testClassNamespace . ';';
                }

                if ($reflectedTest->getShortName() != $testClassName) {
                    if (!$dialog->askConfirmation(
                        $output,
                        $dialog->getQuestion('Class in ' . $fileTestPath . ' is bad, overwrite?', 'yes', '?'),
                        true
                    )
                    ) {
                        $output->writeln('<error>Pomijanie pliku ' . $file->getRealpath() . '</error>');
                        continue;
                    }

                    $src[key(
                        preg_grep('/class.' . addslashes($reflectedTest->getShortName()) . './', $testClassSrc)
                    )] = 'class ' . $testClassName . ' extends WebTestCase';
                }

                //parsowanie use w pliku z testem
                /*
                 * use Doctrine\Common\Annotations;
                $tokenParser = new \Doctrine\Common\Annotations\TokenParser(file_get_contents($fileTestPath));
                $testClassUseStatements = $tokenParser->parseUseStatements($fileTestClassNamespace . '\\' . $fileTestClass);
                if (false === array_key_exists(strtolower($fileClass), $testClassUseStatements))
                {
                    //TODO
                    $output->writeln('<info>Do klasy '.$fileTestClass.' zaimportowano namespace '.$fileClassNamespace.'\\'.$fileClass.'</info>');
                }
                if (false === array_key_exists(strtolower('WebTestCase'), $testClassUseStatements)) {
                    //TODO
                    $output->writeln('<info>Do klasy '.$fileTestClass.' zaimportowano namespace Symfony\Bundle\FrameworkBundle\Test\WebTestCase</info>');
                }
                */
            }

            $classTemplateParameter = array(
                'testClassNamespace' => $testClassNamespace,
                'fileClassNamespace' => $fileClassNamespace,
                'fileClass'          => $fileClass,
                'testClassName'      => $testClassName,
                'methods'            => $methodsContent
            );

            //czy klasa ma argumenty w konstruktorze
            $constructor = $infoClass->getConstructor();
            if ($constructor) {
                if ($constructor->getNumberOfParameters() > 0) {
                    $classTemplateParameter['classConstructorHasParm'] = true;
                    $classTemplateParameter['constructorParameters']   = $constructor->getParameters();
                    //TODO: sprawdzac parametry konsturktora i wypelniac fikcyjnymi danymi/mockami?
                }
            }

            if (in_array(
                pathinfo($file->getRelativePathname())['dirname'],
                array( 'Controller', 'Entity', 'Repository' )
            )
            ) {
                //entity manager
                $classTemplateParameter['insertEm'] = true;
            }

            //zapisz
            if (true === $fileTestExist) {
                $endMethodLine = $reflectedTest->getEndLine() - 1;
                foreach ($methodsContent as $key => $method) {
                    //sprawdzenie czy istnieje metoda w pliku testowym
                    if (!$reflectedTest->hasMethod($key)) {
                        array_splice($testClassSrc, $$endMethodLine, 0, $method);
                        $$endMethodLine = $endMethodLine + count($method);
                        $output->writeln('<info>Add function ' . $key . ' to ' . $fileTestClass . '</info>');
                    }
                }
                file_put_contents($fileTestPath, $testClassSrc);
            } else {
                $this->renderFile($this->templateDir, 'class.php.twig', $fileTestPath, $classTemplateParameter);
            }

            $itemIndex++;

        }

        $output->writeln('<info>Processed ' . $itemIndex . ' files</info>');
    }

    private function getClassFromFile($path)
    {
        $tokens = token_get_all(file_get_contents($path));

        $namespace = null;
        $class     = null;

        for ($i = 0; $i < count($tokens); $i++) {

            if ($tokens[$i][0] === T_NAMESPACE) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j][0] === T_STRING) {
                        //$namespace .= '\\'.$tokens[$j][1];
                        if (null === $namespace) {
                            $namespace .= $tokens[$j][1];
                        } else {
                            $namespace .= '\\' . $tokens[$j][1];
                        }
                    } else {
                        if ($tokens[$j] === '{' || $tokens[$j] === ';') {
                            break;
                        }
                    }
                }
            }

            if ($tokens[$i][0] === T_CLASS) {
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j] === '{') {
                        $class = $tokens[$i + 2][1];
                    }
                }
            }

            if (null !== $namespace && null !== $class) {
                return array( $namespace, $class );
            }
        }
    }

    protected function getDialogHelper()
    {
        $dialog = $this->getHelperSet()->get('dialog');

        if (!$dialog || get_class($dialog) !== 'use Symfony\Component\Console\Helper\DialogHelper') {
            $this->getHelperSet()->set($dialog = new \Symfony\Component\Console\Helper\DialogHelper());
        }

        return $dialog;
    }

    private function ResolveParameterClassName(\ReflectionParameter $reflectionParameter)
    {
        if ($reflectionParameter->isArray()) {
            return null;
        }

        try {
            if ($reflectionParameter->getClass()) {
                return $reflectionParameter->getClass()->name;
            }

        } catch (Exception $exception) {
            $parts = explode(' ', $exception->getMessage(), 3);

            return $parts[1];
        }
    }

    private function validateBundleName($bundle)
    {
        if (!preg_match('/Bundle$/', $bundle)) {
            throw new \InvalidArgumentException('The bundle name must end with Bundle.');
        }

        return $bundle;
    }

}