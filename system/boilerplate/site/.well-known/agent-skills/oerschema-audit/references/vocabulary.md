# OER Schema Vocabulary Reference (v1.2.0)

This is the condensed, audit-ready view of the OER Schema vocabulary as defined in the `oerschema`
repo `app/lib/schema.ts` (version 1.2.0). It is the **single source of truth** for what classes and
properties exist and where each property is valid. Verify every audit recommendation against this
file. External `subClassOf` targets (schema.org, creativecommons) are omitted from the hierarchy
lines below for brevity but are noted where they matter.

## Class hierarchy (internal subClassOf chains)

Roots: `Resource` (subClasses schema.org/Thing + creativecommons/ns#Work) and `Thing`
(subClasses schema.org/Thing). `Intangible` → `Thing`. Datatypes hang off `DataType` → `Class`.

```
Resource
├── Course                      [schema.org/Course]
├── CourseSection               [schema.org/CourseInstance]
├── CourseSyllabus
├── Topic
├── LearningComponent           [schema.org/CreativeWork, AlignmentObject]
│   ├── AssociatedMaterial
│   │   ├── SupportingMaterial
│   │   ├── SupplementalMaterial
│   │   └── ReferencedMaterial
│   └── InstructionalPattern
│       ├── Lesson
│       ├── Unit
│       ├── Module
│       ├── Assessment          [schema.org/Action]
│       │   ├── Quiz
│       │   └── Submission
│       └── Task                [schema.org/Action]
│           ├── Activity
│           │   └── Project
│           └── Practice
└── LearningObjective

TableOfContents                 [schema.org/Thing]
└── TableOfContentsEntry

Thing                           [schema.org/Thing]
├── Intangible                  [schema.org/Intangible]
│   ├── ClassStanding
│   ├── Format
│   │   └── FaceToFaceFormat
│   ├── GradeFormat             [alternateType DataType]
│   │   ├── PointGradeFormat    [alternateType Number]
│   │   ├── LetterGradeFormat   [alternateType Text]
│   │   ├── PercentGradeFormat  [alternateType Integer]
│   │   └── CompletionGradeFormat [alternateType Text]
│   ├── Rubric
│   ├── RubricCriterion
│   ├── RubricScale
│   ├── RubricLevel
│   ├── Class                   [schema.org/Class]
│   ├── Property
│   ├── Enumeration             [schema.org/Enumeration]
│   └── StructuredValue
├── Person                      [schema.org/Person]
├── Organization                [schema.org/Organization]
├── Place                       [schema.org/Place]
└── CreativeWork                [schema.org/CreativeWork]
    └── MediaObject             [schema.org/MediaObject]
        └── ImageObject         [schema.org/ImageObject]

DataType → Class                [schema.org/DataType, rdfs:datatype]
├── Boolean                     [schema.org/Boolean]
│   ├── Yes / No
│   └── True / False            [schema.org/True / schema.org/False]
├── Date / DateTime / Time      [schema.org/*]
├── Number → Integer            [schema.org/Number, schema.org/Integer]
├── Text → URL                  [schema.org/Text, schema.org/URL]
└── ActionType
    ├── Writing / Reading / Making / Researching
    ├── Listening / Watching / Reflecting / Discussing
    └── Observing / Presenting / Assess
```

### Quick class purposes

- **Course** — an instructional course. **CourseSection** — a specific term instance.
  **CourseSyllabus** — syllabus doc. **Topic** — context for a LearningComponent.
- **LearningComponent** — generic base for learning content; carries `forCourse`, `forComponent`,
  `hasComponent`, `doTask`, `hasLearningObjective`, `deliveryFormat`.
- **AssociatedMaterial** → Supporting / Supplemental / Referenced — material tied to a component.
- **InstructionalPattern** — an assembly of learning components. Subtypes: **Lesson**, **Unit**,
  **Module**, **Assessment**, **Task**.
- **Assessment** → **Quiz**, **Submission**. Carries `assessing` (range Activity), `gradingFormat`,
  `rubric`, `material`.
- **Task** → **Activity** → **Project**; **Task** → **Practice**. `Task` carries `typeOfAction`
  and `aiUsageConstraint`; `Activity` adds `assessedBy`, `gradingFormat`, `rubric`.
- **LearningObjective** — expected outcome/skill; carries `skill`, `forCourse`, `forComponent`,
  `coursePrerequisites`.
- **Rubric** family — `Rubric` (`hasCriterion`, `rubricScale`, `rubricType`) → `RubricCriterion`
  (`criterionWeight`); `RubricScale` (`hasLevel`, `pointsRequired`) → `RubricLevel`
  (`levelOrdinal`, `levelPoints`).
- **Format** / **FaceToFaceFormat** — delivery format. **GradeFormat** family — grading format
  datatype-like classes (use `alternateType`).
- **ActionType** family — the learning-verb enumeration used by `typeOfAction`.

## Property tables

### Relationship properties (the ones that wire the pedagogical graph)

| Property | Domain | Range | Notes |
|---|---|---|---|
| `parentOf` | Resource | Resource | parent → child |
| `childOf` | Resource | Resource | child → parent (inverse of parentOf by intent) |
| `forCourse` | Resource | Course | this thing belongs to this Course |
| `forComponent` | LearningComponent | LearningComponent | supports a component (inverse of hasComponent by comment; `inverseOf` field NOT set) |
| `hasComponent` | LearningComponent | LearningComponent | contains a component (inverse of forComponent by comment; `inverseOf` field NOT set) |
| `hasLearningObjective` | InstructionalPattern | LearningObjective | Lesson/Unit/Module/Assessment/Task/etc. → objective |
| `doTask` | LearningComponent | Task | component → task to complete |
| `assessing` | Assessment | Activity | assessment → activity it assesses |
| `assessedBy` | Activity | Assessment | activity → assessment (inverse of assessing by intent) |
| `rubric` | Assessment, Activity | Rubric | links a Rubric to an assessment/activity |
| `material` | Resource | SupportingMaterial | supporting material for a resource |
| `section` | Course | CourseSection | course → its sections |
| `entry` | TableOfContents | TableOfContentsEntry | ToC → entries |
| `forTopic` | Resource | Topic | resource → topic context |
| `hasCriterion` | Rubric | RubricCriterion | rubric → criteria |
| `rubricScale` | Rubric | RubricScale | rubric → scale |
| `hasLevel` | RubricScale | RubricLevel | scale → performance levels |

### Descriptive / scalar properties

| Property | Domain | Range | Notes |
|---|---|---|---|
| `name` | Resource | Text | baseVocab schema.org |
| `description` | Thing | Text | baseVocab schema.org |
| `additionalType` | Thing | Class | for sub-typing via external vocab |
| `image` | Thing | URL, CreativeWork | |
| `mainEntityOfPage` | Thing | URL, CreativeWork | `inverseOf: mainEntity` (external schema.org prop) |
| `sameAs` | Thing | URL | |
| `uri` | Thing | URL | alternateType schema.org/url |
| `mainContent` | Resource | Text | |
| `skill` | LearningObjective | Text | the learned skill |
| `courseIdentifier` | Course | Text | e.g. MATH-100 |
| `sectionIdentifier` | CourseSection | Text | |
| `termOffered` | Course, CourseSection | Text | |
| `coursePrerequisites` | Resource | Course, LearningObjective, LearningComponent, AlignmentObject, Course, Text | |
| `institution` / `department` / `program` | Resource | Organization | |
| `syllabus` | Resource | CourseSyllabus | |
| `primaryInstructor` / `instructor` | Resource | Person | |
| `enrollmentSize` | Resource | Number | |
| `deliveryMode` | Resource | Text | |
| `deliveryFormat` | LearningComponent, Course | Format, Text | |
| `currentStanding` | Resource | ClassStanding | |
| `gradingFormat` | Activity, Assessment | GradeFormat, Text | |
| `typeOfAction` | Task | ActionType | value is an ActionType subclass URI |
| `aiUsageConstraint` | Task | Text, URL | AI-use policy (URL preferred) |
| `associatedMedia` | Resource | MediaObject (external) | |
| `rubricType` | Rubric | Text | analytic / holistic / single-point / checklist |
| `criterionWeight` | RubricCriterion | Number | |
| `pointsRequired` | RubricScale | Boolean | |
| `levelOrdinal` | RubricLevel | Integer | higher = better |
| `levelPoints` | RubricLevel | Number | |

### Meta-vocabulary properties (schema.org-aligned, rarely authored in content)

`rangeIncludes` (Thing→Class), `domainIncludes` (Property→Class), `supersededBy`
(Class/Property/Enumeration → Class/Property/Enumeration), `inverseOf` (Property→Property),
`additionalName` (Thing→Text). These describe the vocabulary itself; you will almost never flag
them in authored content.

## Inheritance rule (read before flagging a domain violation)

A property is valid on a class if the class **or any of its ancestors** (walking `subClassOf`,
ignoring external URIs) is in the property's `domain`. So:

- `hasLearningObjective` (domain `InstructionalPattern`) is valid on `Lesson`, `Unit`, `Module`,
  `Assessment`, `Quiz`, `Submission`, `Task`, `Activity`, `Project`, `Practice` — all subclass
  `InstructionalPattern`.
- `gradingFormat` (domain `Activity`, `Assessment`) is valid on `Activity`, `Project`, `Assessment`,
  `Quiz`, `Submission` — but NOT on `Task` or `Practice` (they subclass `Task`, not `Activity`).
- `typeOfAction` (domain `Task`) is valid on `Task`, `Activity`, `Project`, `Practice` — but NOT on
  `Assessment`/`Quiz`/`Submission` (they subclass `InstructionalPattern` via `Assessment`, not
  `Task`).
- `skill` (domain `LearningObjective`) is valid ONLY on `LearningObjective`.

Same logic for `range`: a value class is valid for a property's range if it **or any ancestor** is
in the range list. `Quiz` satisfies a range of `Assessment` (Quiz subClassOf Assessment).

## Known vocabulary-internal gaps (do NOT report as content errors — defer to oerschema-validation)

- `forComponent` and `hasComponent` describe each other as inverses in their `comment` text but
  neither sets the `inverseOf` field. This is a `schema.ts` consistency gap, not a content error.
- `mainEntityOfPage.inverseOf` is `"mainEntity"`, which is not a property in this vocabulary (it is
  an external schema.org property). Intentional — do not flag.
- `assessing`/`assessedBy` and `parentOf`/`childOf` are inverse-by-intent pairs with no `inverseOf`
  field set. Same as above.
