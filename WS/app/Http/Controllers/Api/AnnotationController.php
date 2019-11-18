<?php
/**
 * RNADetector Web Service
 *
 * @author S. Alaimo, Ph.D. <alaimos at gmail dot com>
 */

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Annotation as AnnotationResource;
use App\Http\Resources\AnnotationCollection;
use App\Models\Annotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnotationController extends Controller
{

    /**
     * JobController constructor.
     */
    public function __construct()
    {
        $this->authorizeResource(Annotation::class, 'annotation');
    }


    /**
     * Display a listing of the resource.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return \App\Http\Resources\AnnotationCollection
     */
    public function index(Request $request): AnnotationCollection
    {
        $perPage = (int)($request->get('per_page') ?? 15);
        if ($perPage < 0) {
            $perPage = 15;
        }

        return new AnnotationCollection(Annotation::paginate($perPage));
    }

    /**
     * Display the specified resource.
     *
     * @param \App\Models\Annotation $annotation
     *
     * @return \App\Http\Resources\Annotation
     */
    public function show(Annotation $annotation): AnnotationResource
    {
        return new AnnotationResource($annotation);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param \App\Models\Annotation $annotation
     *
     * @return \Illuminate\Http\JsonResponse
     * @throws \Exception
     */
    public function destroy(Annotation $annotation): JsonResponse
    {
        $annotation->delete();

        return response()->json(
            [
                'message' => 'Annotation deleted.',
                'errors'  => false,
            ]
        );
    }

}