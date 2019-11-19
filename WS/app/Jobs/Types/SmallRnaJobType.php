<?php
/**
 * RNADetector Web Service
 *
 * @author A. La Ferlita, Ph.D. Student <alessandrolf90 at hotmail dot it>
 */

namespace App\Jobs\Types;


use App\Exceptions\ProcessingJobException;
use App\Jobs\Types\Traits\ConvertsBamToFastqTrait;
use App\Jobs\Types\Traits\RunTrimGaloreTrait;
use App\Models\Annotation;
use App\Models\Reference;
use App\Utils;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Storage;

class SmallRnaJobType extends AbstractJob
{
    use ConvertsBamToFastqTrait, RunTrimGaloreTrait;

    private const FASTQ                = 'fastq';
    private const BAM                  = 'BAM';
    private const HTSEQ_COUNTS         = 'htseq';
    private const FEATURECOUNTS_COUNTS = 'feature-counts';
    private const VALID_INPUT_TYPES    = [self::FASTQ, self::BAM];
    private const VALID_COUNTS_METHODS = [self::HTSEQ_COUNTS, self::FEATURECOUNTS_COUNTS];

    /**
     * Returns an array containing for each input parameter an help detailing its content and use.
     *
     * @return array
     */
    public static function parametersSpec(): array
    {
        return [
            'paired'            => 'A boolean value to indicate whether sequencing strategy is paired-ended or not (Default false)',
            'firstInputFile'    => 'Required, input file for the analysis',
            'secondInputFile'   => 'Required if paired is true and inputType is fastq. The second reads file',
            'inputType'         => 'Required, type of the input file (fastq, bam)',
            'convertBam'        => 'If inputType is bam converts input in another format: fastq.',
            'trimGalore'        => [
                'enable'  => 'A boolean value to indicate whether trim galore should run (This parameter works only for fastq files)',
                'quality' => 'Minimal PHREAD quality for trimming (Default 20)',
                'length'  => 'Minimal reads length (Default 14)',
            ],
            'countingAlgorithm' => 'The counting algorithm htseq or feature-counts (Default htseq)',
            'genome'            => 'An optional name for a reference genome (Default human hg19)',
            'annotation'        => 'An optional name for a genome annotation (Default human hg19)',
            'threads'           => 'Number of threads for this analysis (Default 1)',
        ];

    }

    /**
     * Returns an array containing for each output value an help detailing its use.
     *
     * @return array
     */
    public static function outputSpec(): array
    {
        return [
            'outputFile' => 'Formatted read counts files (If multiple files a zip archive is returned)',
        ];
    }

    /**
     * Returns an array containing rules for input validation.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return array
     */
    public static function validationSpec(Request $request): array
    {
        return [
            'paired'             => ['filled', 'boolean'],
            'firstInputFile'     => ['required', 'string'],
            'secondInputFile'    => [
                Rule::requiredIf(
                    static function () use ($request) {
                        return $request->get('parameters.inputType') === self::FASTQ && ((bool)$request->get(
                                'parameters.paired',
                                false
                            )) === true;
                    }
                ),
                'string',
            ],
            'inputType'          => ['required', Rule::in(self::VALID_INPUT_TYPES)],
            'convertBam'         => ['filled', 'boolean'],
            'trimGalore'         => ['filled', 'array'],
            'trimGalore.enable'  => ['filled', 'boolean'],
            'trimGalore.quality' => ['filled', 'integer'],
            'trimGalore.length'  => ['filled', 'integer'],
            'countingAlgorithm'  => ['filled', Rule::in(self::VALID_COUNTS_METHODS)],
            'genome'             => ['filled', 'alpha_dash', Rule::exists('references', 'name')],
            'annotation'         => ['filled', 'alpha_dash', Rule::exists('annotations', 'name')],
            'threads'            => ['filled', 'integer'],
        ];
    }

    /**
     * Checks the input of this job and returns true iff the input contains valid data
     * The default implementation does nothing.
     *
     * @return bool
     */
    public function isInputValid(): bool
    {
        $paired = (bool)$this->model->getParameter('paired', false);
        $inputType = $this->model->getParameter('inputType');
        $firstInputFile = $this->model->getParameter('firstInputFile');
        $secondInputFile = $this->model->getParameter('secondInputFile');
        $countingAlgorithm = $this->model->getParameter('countingAlgorithm', self::HTSEQ_COUNTS);
        if (!in_array($inputType, self::VALID_INPUT_TYPES, true)) {
            return false;
        }
        if (!in_array($countingAlgorithm, self::VALID_COUNTS_METHODS, true)) {
            return false;
        }
        $disk = Storage::disk('public');
        $dir = $this->model->getJobDirectory() . '/';
        if (!$disk->exists($dir . $firstInputFile)) {
            return false;
        }
        if ($paired && $inputType === self::FASTQ && (empty($secondInputFile) || !$disk->exists(
                    $dir . $secondInputFile
                ))) {
            return false;
        }

        return true;
    }

    /**
     * Runs TopHat
     *
     * @param bool                   $paired
     * @param string                 $firstInputFile
     * @param string|null            $secondInputFile
     * @param \App\Models\Reference  $genome
     * @param \App\Models\Annotation $annotation
     * @param int                    $threads
     *
     * @return string
     * @throws \App\Exceptions\ProcessingJobException
     */
    private function runTophat(
        bool $paired,
        string $firstInputFile,
        ?string $secondInputFile,
        Reference $genome,
        Annotation $annotation,
        int $threads = 1
    ): string {
        $bamOutput = $this->model->getJobTempFileAbsolute('bowtie_output', '.bam');
        $command = [
            'bash',
            self::scriptPath('tophat.bash'),
            '-a',
            $annotation->path,
            '-g',
            $genome->basename(),
            '-t',
            $threads,
            '-f',
            $firstInputFile,
            '-o',
            $bamOutput,
        ];
        if ($paired) {
            $command[] = '-s';
            $command[] = $secondInputFile;
        }
        $output = self::runCommand(
            $command,
            $this->model->getAbsoluteJobDirectory(),
            null,
            null,
            [
                3 => 'Annotation file does not exist.',
                4 => 'Input file does not exist.',
                5 => 'Second input file does not exist.',
                6 => 'Output file must be specified.',
                7 => 'Output directory is not writable.',
                8 => 'Unable to find output bam file.',
            ]
        );
        if (!file_exists($bamOutput)) {
            throw new ProcessingJobException('Unable to create TopHat output file');
        }
        $this->log($output);

        return $bamOutput;
    }

    /**
     * Runs HTseq-count
     *
     * @param string                 $countingInputFile
     * @param \App\Models\Annotation $annotation
     * @param int                    $threads
     *
     * @return array
     * @throws \App\Exceptions\ProcessingJobException
     */
    private function runHTSEQ(string $countingInputFile, Annotation $annotation, int $threads = 1): array
    {
        $htseqOutputRelative = $this->model->getJobTempFile('htseq_output', '.txt');
        $htseqOutput = $this->model->absoluteJobPath($htseqOutputRelative);
        $htseqOutputUrl = \Storage::disk('public')->url($htseqOutput);
        $output = self::runCommand(
            [
                'bash',
                self::scriptPath('htseqcount.bash'),
                '-a',
                $annotation->path,
                '-b',
                $countingInputFile,
                '-t',
                $threads,
                '-o',
                $htseqOutput,
            ],
            $this->model->getAbsoluteJobDirectory(),
            null,
            null,
            [
                3 => 'Annotation file does not exist.',
                4 => 'Input file does not exist.',
                5 => 'Output file must be specified.',
                6 => 'Output directory is not writable.',
            ]
        );
        if (!file_exists($htseqOutput)) {
            throw new ProcessingJobException('Unable to create HTseq-count output file');
        }
        $this->log($output);

        return [$htseqOutputRelative, $htseqOutputUrl];
    }

    /**
     * Runs FeatureCount
     *
     * @param string                 $countingInputFile
     * @param \App\Models\Annotation $annotation
     * @param int                    $threads
     *
     * @return array
     * @throws \App\Exceptions\ProcessingJobException
     */
    private function runFeatureCount(string $countingInputFile, Annotation $annotation, int $threads = 1): array
    {
        $featurecountOutputRelative = $this->model->getJobTempFile('featurecount_output', '.txt');
        $featurecountOutput = $this->model->absoluteJobPath($featurecountOutputRelative);
        $featurecountOutputUrl = \Storage::disk('public')->url($featurecountOutput);
        $output = self::runCommand(
            [
                'bash',
                self::scriptPath('htseqcount.bash'),
                '-a',
                $annotation->path,
                '-b',
                $countingInputFile,
                '-t',
                $threads,
                '-o',
                $featurecountOutput,
            ],
            $this->model->getAbsoluteJobDirectory(),
            null,
            null,
            [
                3 => 'Annotation file does not exist.',
                4 => 'Input file does not exist.',
                5 => 'Output file must be specified.',
                6 => 'Output directory is not writable.',
            ]
        );

        if (!file_exists($featurecountOutput)) {
            throw new ProcessingJobException('Unable to create FeatureCount output file');
        }
        $this->log($output);

        return [$featurecountOutputRelative, $featurecountOutputUrl];
    }

    /**
     * Handles all the computation for this job.
     * This function should throw a ProcessingJobException if something went wrong during the computation.
     * If no exceptions are thrown the job is considered as successfully completed.
     *
     * @throws \App\Exceptions\ProcessingJobException
     */
    public function handle(): void
    {
        $this->log('Starting small ncRNAs analysis.');
        $paired = (bool)$this->model->getParameter('paired', false);
        $inputType = $this->model->getParameter('inputType');
        $convertBam = (bool)$this->model->getParameter('convertBam', false);
        $firstInputFile = $this->model->getParameter('firstInputFile');
        $secondInputFile = $this->model->getParameter('secondInputFile');
        $trimGaloreEnable = (bool)$this->model->getParameter('trimGalore.enable', $inputType === self::FASTQ);
        $trimGaloreQuality = (int)$this->model->getParameter('trimGalore.quality', 20);
        $trimGaloreLength = (int)$this->model->getParameter('trimGalore.length', 14);
        $genomeName = $this->model->getParameter('genome', env('HUMAN_GENOME_NAME'));
        $annotationName = $this->model->getParameter('annotation', env('HUMAN_SNCRNA_ANNOTATION_NAME'));
        $threads = (int)$this->model->getParameter('threads', 1);
        $countingAlgorithm = $this->model->getParameter('countingAlgorithm', self::HTSEQ_COUNTS);
        $genome = Reference::whereName($genomeName)->firstOrFail();
        $annotation = Annotation::whereName($annotationName)->firstOrFail();
        if ($inputType === self::BAM && $convertBam) {
            $inputType = self::FASTQ;
            $this->log('Converting BAM to FASTQ.');
            [$firstInputFile, $secondInputFile, $bashOutput] = self::convertBamToFastq(
                $this->model,
                $paired,
                $firstInputFile
            );
            $this->log($bashOutput);
            $this->log('BAM converted to FASTQ.');
        }
        [$firstTrimmedFastq, $secondTrimmedFastq] = [$firstInputFile, $secondInputFile];
        if ($inputType === self::FASTQ && $trimGaloreEnable) {
            $this->log('Trimming reads using TrimGalore.');
            [$firstTrimmedFastq, $secondTrimmedFastq] = self::runTrimGalore(
                $this->model,
                $paired,
                $firstInputFile,
                $secondInputFile,
                $trimGaloreQuality,
                $trimGaloreLength
            );
            $this->log('Trimming completed.');
        }
        $this->log('Aligning reads with TopHat');
        $countingInputFile = $this->runTophat(
            $paired,
            $firstTrimmedFastq,
            $secondTrimmedFastq,
            $genome,
            $annotation,
            $threads
        );
        $this->log('Alignment completed.');
        if ($countingAlgorithm === self::HTSEQ_COUNTS) {
            $this->log('Starting reads counting with HTseq-count');
            [$htseqOutput, $htseqOutputUrl] = $this->runHTSEQ($countingInputFile, $annotation, $threads);
            $this->log('Reads counting completed');
            $this->model->setOutput(
                [
                    'outputFile' => ['path' => $htseqOutput, 'url' => $htseqOutputUrl],
                ]
            );
            $this->log('Analysis completed.');
            $this->model->save();

        } else {
            $this->log('Starting reads counting with FeatureCount');
            [$featurecountOutput, $featurecountOutputUrl] = $this->runFeatureCount(
                $countingInputFile,
                $annotation,
                $threads
            );
            $this->log('Reads counting completed');
            $this->model->setOutput(
                [
                    'outputFile' => ['path' => $featurecountOutput, 'url' => $featurecountOutputUrl],
                ]
            );
            $this->log('Analysis completed.');
            $this->model->save();
        }
    }

    /**
     * Returns a description for this job
     *
     * @return string
     */
    public static function description(): string
    {
        return 'Runs small ncRNAs analysis from sequencing data';
    }
}
