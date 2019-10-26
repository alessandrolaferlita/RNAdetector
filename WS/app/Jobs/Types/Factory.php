<?php


namespace App\Jobs\Types;

use App\Exceptions\ProcessingJobException;
use App\Models\Job as JobModel;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

class Factory
{

    /**
     * Given a Job Model, this method builds an object that will be able to process this job
     *
     * @param \App\Models\Job $jobModel
     * @return \App\Jobs\Types\AbstractJob
     * @throws \App\Exceptions\ProcessingJobException
     */
    public static function get(JobModel $jobModel): AbstractJob
    {
        $jobClass = '\App\Jobs\Types\\' . Str::studly($jobModel->job_type);
        if (!class_exists($jobClass)) {
            throw new ProcessingJobException('Unable to find a class suitable for this job.');
        }
        try {
            $r = new \ReflectionClass($jobClass);
            if ($r->isSubclassOf(AbstractJob::class) && !$r->isAbstract() && $r->getConstructor()->getNumberOfRequiredParameters() === 1) {
                /** @var \App\Jobs\Types\AbstractJob $obj */
                $obj = $r->newInstance($jobModel);
                return $obj;
            }
        } catch (\ReflectionException $e) {
            throw new ProcessingJobException('An error occurred during type class instantiation', 0, $e);
        }
        throw new ProcessingJobException('The type of job ' . $jobModel->id . ' is not valid!');
    }

    /**
     * Returns a list of all available job types
     *
     * @return \Illuminate\Support\Collection
     */
    public static function listTypes(): Collection
    {
        $list   = [];
        $finder = new Finder();
        $finder->files()->name('*Type.php')->in(app_path('Jobs/Types/'));
        foreach ($finder as $file) {
            $ns = '\App\Jobs\Types';
            if ($relativePath = $file->getRelativePath()) {
                $ns .= '\\' . str_replace('/', '\\', $relativePath);
            }
            $class = $ns . '\\' . $file->getBasename('.php');
            try {
                $r = new \ReflectionClass($class);
                if ($r->isSubclassOf(AbstractJob::class) && !$r->isAbstract() && $r->getConstructor()->getNumberOfRequiredParameters() === 1) {
                    $list[] = [
                        'id'          => Str::snake($file->getBasename('.php')),
                        'description' => call_user_func([$class, 'description']),
                    ];
                }
            } catch (\ReflectionException $ignore) {
                continue;
            }
        }
        return Collection::make($list);
    }

}
