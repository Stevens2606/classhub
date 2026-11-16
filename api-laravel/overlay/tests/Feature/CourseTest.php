<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCatalog;
use Tests\TestCase;

class CourseTest extends TestCase
{
    use CreatesCatalog;
    use RefreshDatabase;

    public function test_course_list_shows_only_active_courses(): void
    {
        $this->course(150000, ['title' => 'Mathematics Fundamentals']);
        $this->course(250000, ['title' => 'Physics Intensive']);
        $this->course(100000, ['title' => 'Hidden Draft', 'is_active' => false]);

        $this->getJson('/api/courses')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Mathematics Fundamentals')
            ->assertJsonPath('data.1.title', 'Physics Intensive');
    }

    public function test_course_detail_and_unknown_course(): void
    {
        $course = $this->course();

        $this->getJson("/api/courses/{$course->id}")
            ->assertOk()
            ->assertJsonPath('data.price', 150000);

        $this->getJson('/api/courses/999999')->assertNotFound();
    }

    public function test_content_requires_a_login(): void
    {
        $course = $this->course();

        $this->getJson("/api/courses/{$course->id}/materials")->assertStatus(401);
        $this->getJson("/api/courses/{$course->id}/tryouts")->assertStatus(401);
    }

    public function test_content_is_forbidden_without_a_paid_order(): void
    {
        Http::fake();
        $course = $this->course();
        $student = User::factory()->create();
        $this->order($student, $course, 'pending');
        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/materials")->assertForbidden();
        $this->getJson("/api/courses/{$course->id}/tryouts")->assertForbidden();

        Http::assertNothingSent(); // the content service is not even asked
    }

    public function test_someone_elses_paid_order_gives_no_access(): void
    {
        Http::fake();
        $course = $this->course();
        $this->order(User::factory()->create(), $course, 'paid');
        Sanctum::actingAs(User::factory()->create());

        $this->getJson("/api/courses/{$course->id}/materials")->assertForbidden();
    }

    public function test_a_paying_student_gets_materials_from_the_content_service(): void
    {
        Http::fake(['content.test/*' => Http::response(['data' => [
            ['id' => 1, 'title' => 'Intro to Algebra', 'type' => 'pdf', 'url' => 'https://example.com/a.pdf'],
        ]])]);
        $course = $this->course();
        $student = User::factory()->create();
        $this->order($student, $course, 'paid');
        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/materials")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Intro to Algebra');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Internal-Key', 'test-key')
            && str_contains($request->url(), '/materials')
            && str_contains($request->url(), 'course_id='.$course->id));
    }

    public function test_a_paying_student_gets_tryouts_from_the_content_service(): void
    {
        Http::fake(['content.test/*' => Http::response(['data' => [
            ['id' => 7, 'title' => 'Algebra Tryout 1', 'duration_minutes' => 60, 'question_count' => 20],
        ]])]);
        $course = $this->course();
        $student = User::factory()->create();
        $this->order($student, $course, 'paid');
        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/tryouts")
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Algebra Tryout 1');

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/tryouts'));
    }

    public function test_content_degrades_to_503_when_the_content_service_is_down(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Connection refused');
        });
        $course = $this->course();
        $student = User::factory()->create();
        $this->order($student, $course, 'paid');
        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/materials")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Course content is temporarily unavailable. Please try again later.');

        // The rest of the platform keeps working while the microservice is down.
        $this->getJson('/api/courses')->assertOk();
        $this->getJson('/api/auth/me')->assertOk();
        $this->getJson('/api/orders')->assertOk();
    }

    public function test_content_degrades_to_503_when_the_content_service_errors(): void
    {
        Http::fake(['content.test/*' => Http::response(['error' => 'boom'], 500)]);
        $course = $this->course();
        $student = User::factory()->create();
        $this->order($student, $course, 'paid');
        Sanctum::actingAs($student);

        $this->getJson("/api/courses/{$course->id}/tryouts")->assertStatus(503);
    }
}
