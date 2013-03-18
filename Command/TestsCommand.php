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
            ->setName('ee:tests')
            ->setDescription('EE Tests Generator')
            ->addArgument('bundle', InputArgument::REQUIRED, 'Bundle name')
            ->addOption('test-private', null, InputOption::VALUE_OPTIONAL, 'Test private and protected method', true)
            ->addOption(
            'exclude',
            null,
            InputOption::VALUE_OPTIONAL | InputArgument::IS_ARRAY,
            'Exclude folders',
            $this->getExcludeList()
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
        foreach ($input->getOption('exclude') as $dir) {
            $dirs[] = $dir;
        }

        $finder->files()->in($bundle->getPath())->name('*.php')->exclude($dirs);
        $bundleTestPath = $bundle->getPath() . '/Tests/';

        $itemIndex = 0;
        foreach ($finder as $file) {
            $fileTestExist   = false;
            //folder exist
            if (!$this->filesystem->exists($bundleTestPath . $file->getRelativePath())) {
                $this->filesystem->mkdir($bundleTestPath . $file->getRelativePath());
            }

            list( $fileClassNamespace, $fileClass ) = $this->getClassFromFile($file->getRealpath());
            $infoClass = new \ReflectionClass( $fileClassNamespace . '\\' . $fileClass );

            if (true === $infoClass->isAbstract() || true === $infoClass->isInterface()) {
                continue;
            }

            if (0 === strlen($file->getRelativePath()) ) {
                continue;
            }

            //file
            $realPathInfo = pathinfo($file->getRealpath());

            $testClassName = $realPathInfo['filename'] . 'Test';
            $fileTestPath  = $bundleTestPath . $file->getRelativePath() . '/' . $testClassName . '.php';

            $relativePathInfo = pathinfo($file->getRelativePath());

            if (0 === strlen($relativePathInfo['filename'])) {
                $testClassNamespace = $bundle->getNamespace() . '\Tests';
            } else {
                $testClassNamespace = $bundle->getNamespace() . '\Tests\\' . $relativePathInfo['filename'];
            }

            $methodsContent = Array();
            //method in class
            foreach ($infoClass->getMethods() as $method) {
                if (!$method->isConstructor() && !$method->isDestructor() && !$method->isAbstract() && $method->class === $infoClass->getName()){

                    if (true !== $input->getOption('test-private') && (true === $method->isPrivate() || true === $method->isProtected())) {
                        continue;
                    }

                    $methodName = $method->getName();
                    //method parameters
                    $methodParameters = Array();

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

                    $methodParameters = implode(', ', $methodParameters);

                    $methodsContent['test' . ucfirst($methodName)] = $this->render(
                        $this->templateDir,
                        'method.php.twig',
                        array(
                            'fileClassNamespace' => $fileClassNamespace,
                            'fileClass'          => $fileClass,
                            'methodName'         => $methodName,
                            'ucfirstMethodName'  => ucfirst($methodName),
                            'methodParameters'   => $methodParameters,
                            'methodPrivate'      => $method->isPrivate(),
                            'methodProtected'    => $method->isProtected()
                        )
                    );
                }
            }

            //if method no exist continue loop
            if (0 === count($methodsContent)) {
                continue;
            }

            //file with test class exist?
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
                        $output->writeln('<error>Skipping file ' . $file->getRealpath() . '</error>');
                        continue;
                    }

                    $src[key(
                        preg_grep('/class.' . addslashes($reflectedTest->getShortName()) . './', $testClassSrc)
                    )] = 'class ' . $testClassName . ' extends WebTestCase';
                }

            }

            $classTemplateParameter = array(
                'testClassNamespace' => $testClassNamespace,
                'fileClassNamespace' => $fileClassNamespace,
                'fileClass'          => $fileClass,
                'testClassName'      => $testClassName,
                'methods'            => $methodsContent
            );

            //constructor have arguments?
            $constructor = $infoClass->getConstructor();
            if ($constructor) {
                if ($constructor->getNumberOfParameters() > 0) {
                    $classTemplateParameter['classConstructorHasParm'] = true;
                    $constructorParameters = array();
                    foreach ($constructor->getParameters() as $parameter) {
                        if ($this->ResolveParameterClassName($parameter)) {
                            $constructorParameters[] =  $this->ResolveParameterClassName($parameter) . ' $' . $parameter->getName();
                        }
                        else {
                            $constructorParameters[] = '$'.$parameter->getName();
                        }
                    }

                    $classTemplateParameter['constructorParameters']   = $constructorParameters;
                }
            }

            if (in_array(
                $relativePathInfo['dirname'],
                array( 'Controller', 'Entity', 'Repository' )
            )
            ) {
                //entity manager
                $classTemplateParameter['insertEm'] = true;
            }

            //save file
            if (true === $fileTestExist) {
                $endMethodLine = $reflectedTest->getEndLine() - 1;
                foreach ($methodsContent as $key => $method) {
                    //check if method exist
                    if (!$reflectedTest->hasMethod($key)) {
                        array_splice($testClassSrc, $endMethodLine, 0, $method);
                        $$endMethodLine = $endMethodLine + count($method);
                        $output->writeln('<info>Add function ' . $key . ' to ' . $fileTestClass . '</info>');
                    }
                }
                file_put_contents($fileTestPath, $testClassSrc);
            } else {
                $this->renderFile($this->templateDir, 'class.php.twig', $fileTestPath, $classTemplateParameter);
                $output->writeln('<info>Created file ' . $fileTestPath . '</info>');
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

    private function getExcludeList() {
        return array( 'Tests', 'Entity', 'DependencyInjection', 'DataFixtures', 'Form', 'Security', 'Doctrine', 'EventListener', 'Listener', 'Resources', 'Twig', 'TwigExtension', 'Model' );
    }

}