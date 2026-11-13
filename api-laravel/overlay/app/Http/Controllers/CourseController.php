<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Order;
use App\Services\ContentServiceClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CourseController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Course::where('is_active', true)->orderBy('id')->get()]);
    }

    public function show(Course $course): JsonResponse
    {
        return response()->json(['data' => $course]);
    }

    public function materials(Request $request, Course $course, ContentServiceClient $content): JsonResponse
    {
        return $this->fromContentService($request, $course, fn () => $content->materials($course->id));
    }

    public function tryouts(Request $request, Course $course, ContentServiceClient $content): JsonResponse
    {
        return $this->fromContentService($request, $course, fn () => $content->tryouts($course->id));
    }

    /**
     * Only paid students may read course content. If the content microservice is down,
     * only this endpoint degrades (503); login, browsing courses and payments keep working.
     */
    private function fromContentService(Request $request, Course $course, callable $fetch): JsonResponse
    {
        $enrolled = Order::where('user_id', $request->user()->id)
            ->where('course_id', $course->id)
            ->where('status', Order::PAID)
            ->exists();

        if (! $enrolled) {
            return response()->json(['message' => 'Enroll in this course to access its content.'], 403);
        }

        try {
            return response()->json(['data' => $fetch()]);
        } catch (ConnectionException|RequestException $e) {
            Log::warning('Content service unavailable: '.$e->getMessage());

            return response()->json([
                'message' => 'Course content is temporarily unavailable. Please try again later.',
            ], 503);
        }
    }
}
