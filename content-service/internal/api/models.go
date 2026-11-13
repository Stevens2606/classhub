package api

// Material is a learning resource (PDF, video, or article) that belongs to a course.
type Material struct {
	ID       int64  `json:"id"`
	CourseID int64  `json:"course_id"`
	Title    string `json:"title"`
	Type     string `json:"type"`
	URL      string `json:"url"`
	Position int    `json:"position"`
}

// Tryout is a timed practice exam that belongs to a course.
type Tryout struct {
	ID              int64  `json:"id"`
	CourseID        int64  `json:"course_id"`
	Title           string `json:"title"`
	DurationMinutes int    `json:"duration_minutes"`
	QuestionCount   int    `json:"question_count"`
}
