/*
 * The English version of the public website copy in site.ts. Same shapes,
 * same images, same structure; only the text differs. Arabic is the
 * reference text: this is a faithful rendering of it, not new claims.
 */

import { siteAr } from './site'
import type { SiteContent } from './site'

export const siteEn: SiteContent = {
  award: {
    name: 'Dr. Abdulkader Sankari Award for Culture, Heritage and Creativity',
    shortName: 'Dr. Abdulkader Sankari Award',
    slogan: 'Loyalty to impact... empowering initiative',
  },

  organizations: {
    rowad: { name: 'Al-Rowad Foundation for Cooperation and Development', url: siteAr.organizations.rowad.url },
    hand: { name: 'HAND', url: siteAr.organizations.hand.url },
  },

  pages: [
    {
      href: '/',
      label: 'Home',
      labelEn: 'Home',
      description:
        'The Dr. Abdulkader Sankari Award for Culture, Heritage and Creativity: an annual award that discovers and honors the initiatives, individuals and teams creating real, documented impact in Syria, implemented by Al-Rowad Foundation for Cooperation and Development.',
    },
    {
      href: '/about',
      label: 'About the award',
      labelEn: 'About the award',
      description: "The award's purpose and values, its focus on documented impact, the implementing organization, and its link to Dr. Abdulkader Sankari's journey of humanitarian and development giving.",
    },
    {
      href: '/journey',
      label: 'Award journey',
      labelEn: 'Award journey',
      description: 'The stages of the first cycle: preparation, launch and receiving applications, administrative review, independent judging, and the planned Award Week.',
    },
    {
      href: '/eligibility',
      label: 'Eligibility',
      labelEn: 'Eligibility',
      description: "The eligibility conditions shared by the award's three domains, the documents and evidence of impact required, the rules preventing conflicts of interest, and the evaluation principles.",
    },
  ],

  hero: {
    ...siteAr.hero,
    lead: 'An annual Syrian platform that discovers the initiatives, individuals and teams working quietly and creating real, documented impact, honoring them and connecting them with opportunities for empowerment, training and networking.',
    portrait: {
      ...siteAr.hero.portrait,
      alt: 'Portrait of Dr. Abdulkader Sankari, smiling, wearing a white ghutra, black agal and prescription glasses.',
      caption: 'Dr. Abdulkader Sankari',
    },
  },

  domains: [
    {
      ...siteAr.domains[0],
      label: 'Culture and Arts',
      intro: 'Celebrates reading, the arts and cultural production as impact that is written and shared.',
      subcategories: [
        { label: 'Culture', scope: 'Work in reading, knowledge and cultural production that creates impact written and shared in society.' },
        { label: 'Arts', scope: 'Artistic and creative work that delivers tangible cultural impact beyond its maker, reaching its audience and community.' },
      ],
    },
    {
      ...siteAr.domains[1],
      label: 'Heritage',
      intro: 'Celebrates the preservation of tangible and intangible heritage, and the documentation of crafts, stories and cultural elements as impact that is safeguarded and shared.',
      subcategories: [
        { label: 'Tangible heritage', scope: 'Safeguarding and documenting the physical witnesses of collective memory: crafts, sites, artifacts and the material heritage that carries identity.' },
        { label: 'Intangible heritage', scope: 'Preserving and passing on living heritage: stories, oral tradition, practices and inherited knowledge.' },
      ],
    },
    {
      ...siteAr.domains[2],
      label: 'Creativity',
      intro: 'Celebrates initiatives that bring people around a shared value, ideas that turn into opportunities, and precision and continuous improvement as impact that is made in practice.',
      subcategories: [
        { label: 'Social responsibility', scope: 'Initiatives that bring individuals and organizations around a shared value and serve the community.' },
        { label: 'Entrepreneurship', scope: "An idea taking off and turning into an opportunity, and the growth and expansion that carry a project on its journey toward continuity and creating value." },
        { label: 'Quality', scope: "Precision and continuous improvement, with quality as a standard that protects the value of the work and confirms that its impact deserves recognition." },
      ],
    },
  ],

  about: {
    philosophy:
      "The award stands as a moral value expressing loyalty to Dr. Abdulkader Sankari's journey of humanitarian and development giving. It aims to establish a trusted annual Syrian platform that discovers and honors the lesser-known initiatives, individuals and teams that work quietly and create real impact.",
    statement: 'The award does not celebrate achievement only as a result, but the value it creates, the memory it preserves, and the initiative that opens a path for others.',
    aims: [
      { title: 'Discovering overlooked impact', text: 'Discovering and honoring the lesser-known initiatives, individuals and teams that create real, documented impact.' },
      { title: 'Protecting memory and heritage', text: 'Protecting collective memory and heritage as impact that is safeguarded and shared.' },
      { title: 'Empowerment after recognition', text: 'Connecting honorees with opportunities for empowerment, training and networking, to ensure the sustainability of community development.' },
    ],
    values: [
      { title: 'Loyalty', text: 'Loyalty to a journey of humanitarian and development giving, and to the impact its people make away from the spotlight.' },
      { title: 'Documented impact', text: 'The award honors what has actually been achieved and is proven by evidence, not theoretical ideas or proposals whose implementation has not begun.' },
      { title: 'Integrity and transparency', text: 'Clear governance, independent committees, and strict prevention of conflicts of interest at every stage of the award.' },
      { title: 'Empowerment', text: 'Recognition is a beginning, not an end: opportunities for training, networking and growth after the recognition stage.' },
    ],
    impact:
      'Applying to the award rests on tangible, documented impact in the real world: a positive change the work has made in the lives of its beneficiaries and the community, proven by documentation files, reports, photos, videos and testimonials. Applications are therefore built on evidence, and every application is evaluated against published criteria.',
    organization: [
      { org: 'rowad' },
      ' is a licensed non-governmental organization in Syria that focuses on turning humanitarian work into sustainable development work that puts people at the heart of its priorities. It is the implementing organization of the award.',
    ],
  },

  legacy: {
    ...siteAr.legacy,
    paragraphs: [
      [
        'Dr. Abdulkader Sankari left Aleppo in 1970 to study pharmacy in Alexandria and Cairo, then began his career as a lecturer at United Arab Emirates University in Al Ain. He is an Emirati businessman of Syrian origin who founded and chairs ',
        { org: 'rowad' },
        ' and ',
        { org: 'hand' },
        ', through which humanitarian and development initiatives have been carried out in education, relief, economic empowerment and capacity building.',
      ],
      [
        'Among what the institutions he chairs have carried out is the rehabilitation of the Umayyad Mosque in Damascus in a way that preserves its historical and religious value. He is known for his long support of humanitarian and charitable initiatives in Syria, often taking care that his giving remains away from the spotlight.',
      ],
    ],
    bridge: 'From this meaning the award that carries his name begins: that real impact is seen, and that its people are honored even when they work away from the spotlight.',
    photo: {
      ...siteAr.legacy.photo,
      alt: 'Dr. Abdulkader Sankari in Emirati dress standing with his companions in a hall of the Umayyad Mosque, beneath a Qur’anic inscription in mosaic.',
      caption: 'During his visit to the Umayyad Mosque in Damascus in May 2026, his first visit to Damascus since 2011, accompanied by a number of officials and representatives of the two organizations.',
    },
  },

  confirmedSchedule: {
    deadline: 'Applications close at the end of 15 November 2026 — Damascus time',
    judging: 'Judging period: 16 to 25 November 2026',
  },

  journey: [
    {
      key: 'preparation',
      title: 'Preparation',
      summary: 'Setting up the award before applications open.',
      points: ['Consultative meetings with official institutions and civil society organizations.', "Planning the award and preparing its documents and criteria.", 'Preparing the online application platform.'],
    },
    {
      key: 'application',
      title: 'Launch and receiving applications',
      summary: 'Opening the online platform to receive applications.',
      points: [
        'Applying through the platform: create an account, then basic information, then nomination category and work details, then work files and evidence of impact, then “Submit application”.',
        "Orientation sessions and workshops held by the award team to guide applicants in completing the requirements and preparing evidence, as announced by the award administration.",
      ],
    },
    {
      key: 'screening',
      title: 'Administrative review',
      summary: 'Every submitted application reaches the award administration first.',
      points: [
        'An initial review that the application meets the technical and legal conditions and that its information and documents are complete.',
        'The award administration refers qualifying applications to the judging committee of the relevant main domain; no application reaches the judges before this referral.',
      ],
    },
    {
      key: 'judging',
      title: 'Independent judging',
      summary: 'An independent judging committee for each main domain, of 3 or 5 judges.',
      points: [
        'The committees study the applications referred to them and evaluate them against the published evaluation criteria, proposing levels of recognition or withholding.',
        'The integrity and grievances committee verifies the prevention of conflicts of interest and decides on procedural objections.',
        'The board of trustees adopts and procedurally ratifies the results, without interfering in the technical evaluation.',
      ],
    },
    {
      key: 'recognition',
      title: 'Award Week and recognition',
      summary: 'A planned stage; its date will be announced later.',
      points: [
        'Planned interactive events: art exhibitions, workshops, performances and the closing ceremony.',
        'Announcement of the winners and the financial and development recognition during Award Week.',
        'Then connecting the honorees with opportunities for empowerment, training and networking.',
      ],
    },
  ],

  eligibility: {
    conditions: [
      { title: 'Geographic scope', text: 'The work, whether an initiative, project or activity, must exist and be carried out inside Syria, with its base and activity within the Syrian geographic scope in this cycle.' },
      { title: 'Documented impact', text: 'The work must have tangible, documented impact in the real world, and must not be a theoretical idea or a proposal whose implementation has not begun.' },
      { title: 'Fit with a domain', text: 'The work must fit one of the three announced main domains and be submitted within one of its categories.' },
      { title: 'Completeness and eligibility', text: 'The required information, supporting documents and evidence of impact must be provided, and the application must be free of any breach of the technical or legal conditions.' },
      { title: 'Integrity and transparency', text: 'Commitment to ethics, integrity and transparency in all the information and reports submitted.' },
      { title: 'Documenting the impact story', text: "Consent to documenting the impact story in the media and including it in the foundation's knowledge archive and open digital database." },
      { title: 'Participation in Award Week', text: 'Commitment to take part in the planned Award Week events (the exhibition, workshops and forum) if shortlisted for the final round.' },
    ],
    applicants: 'Individuals, teams, initiatives, official institutions and organizations may apply. When registering on the platform, the application is submitted in the name of an individual or an institution.',
    evidence: {
      intro: 'The application is built on evidence that proves the work is complete and shows its impact: documentation files, procedural reports, photos, videos and testimonials.',
      items: [
        'Work description document: required, one file in PDF, DOC or DOCX format.',
        'Additional supporting documents: optional, up to 20 files in the same formats.',
        'Work documentation videos: optional, up to 10 clips in MP4 or WebM format.',
        'Additional documentation links: optional, up to 20 links.',
      ],
    },
    conflict: [
      'Members of the board of trustees, the judging committees, the integrity committee, and the award administration staff at ',
      { org: 'rowad', label: 'Al-Rowad Foundation' },
      ' are strictly prohibited from applying to the award, or from intervening in or influencing any application related to them, to guarantee complete integrity and impartiality.',
    ],
    criteria: [
      { title: 'Originality and innovation', text: 'Offering pioneering or community solutions that are innovative and address real local needs.' },
      { title: 'Credibility and depth of community impact', text: 'The scale of the tangible positive change the work has made in the lives of beneficiaries and the community, and how verifiable that impact is through the evidence submitted.' },
      { title: 'Sustainability and scalability', text: 'The ability of the work to continue and grow by itself after the recognition stage.' },
      { title: 'Governance and process management', text: 'Clear management and documentation models, financial and procedural transparency, and mechanisms for dealing with beneficiaries.' },
      { title: 'Preserving heritage and identity', text: 'How far the work contributes to safeguarding collective memory and cultural heritage, tangible or intangible.' },
    ],
    shared: 'The same conditions and evaluation criteria apply to the three domains and their seven categories; each category’s definition states only its scope, with no additional conditions of its own.',
  },

  closing: {
    statement: 'An award that celebrates those who create impact... and keep it present in memory and community',
  },
}
