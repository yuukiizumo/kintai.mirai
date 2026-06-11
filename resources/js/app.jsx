import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import {
    CalendarDays,
    ChevronLeft,
    ChevronRight,
    Clock,
    Coffee,
    Download,
    FileText,
    LogOut,
    Pencil,
    Save,
    Send,
    Settings,
    Trash2,
    UserRound,
    X,
} from 'lucide-react';
import { Fragment, useEffect, useMemo, useRef, useState } from 'react';

const japanDateFormatter = new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Tokyo',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
});
const today = japanDateFormatter.format(new Date());
const currentMonth = today.slice(0, 7);
const pageSize = 20;
const historyDepartmentPageSize = 15;
const monthFormatter = new Intl.DateTimeFormat('ja-JP', {
    timeZone: 'Asia/Tokyo',
    year: 'numeric',
    month: 'long',
});
const blankForm = {
    id: null,
    user_id: '',
    work_date: today,
    clock_in: '09:00',
    clock_out: '18:00',
    declared_clock_in: '',
    declared_clock_out: '',
    declared_break_minutes: '',
    work_location: '',
    meal_percentage: '',
    missed_meal: false,
    break_minutes: 60,
    status: 'completed',
    request_reason_category: '私用のため',
    request_reason: '',
    request_absent_start_time: '',
    request_absent_end_time: '',
    request_late_start_time: '',
    request_late_end_time: '',
    request_early_leave_start_time: '',
    request_early_leave_end_time: '',
    note: '',
    admin_comment: '',
    attendance_requests: [],
};

const requestTypes = {
    absence: '欠勤',
    late: '遅刻',
    early_leave: '早退',
    paid_leave: '有給',
    morning_paid_leave: '前半有給',
    afternoon_paid_leave: '後半有給',
    overtime: '時間外勤務',
    business_support: '業務対応届',
    change: '変更届',
    care_service: '介護サービス利用',
    off_hours_medical: '勤務時間外通院',
};

const requestReasonCategories = [
    '私用のため',
    '体調不良のため',
    '家庭の事情のため',
    '交通機関遅延のため',
    'その他',
];

const attendanceRequestLinkedStatuses = [
    'absence',
    'late',
    'early_leave',
    'late_and_early_leave',
    'paid_leave',
    'morning_paid_leave',
    'afternoon_paid_leave',
];

const calendarEntryTypes = {
    planned_vacation: '計画有給',
    holiday_off: '休日',
    saturday_work: '土曜出勤日',
    self_report_due: '自己管理レポート提出日',
    free_attendance_8: '自由出勤日（-8日）',
    free_attendance_4: '自由出勤日（-4日）',
};

const blankCalendarEntryForm = {
    date: today,
    type: 'holiday_off',
    description: '',
    processed: false,
};

const blankSelfManagementReportForm = {
    report_date: '',
    work_rating: '',
    life_rating: '',
    monthly_reflection: '',
    next_month_goal: '',
    skill_progress: '',
    activity_status: '',
    activity_detail: '',
    other: '',
};

const selfManagementReportFields = [
    ['work_rating', '仕事評価', 'radio'],
    ['life_rating', '生活評価', 'radio'],
    ['monthly_reflection', '当月の振り返り', 'textarea'],
    ['next_month_goal', '来月の目標', 'textarea'],
    ['skill_progress', '一般就労に向けてスキルアップはできていますか？', 'yesNoRadio'],
    ['activity_status', '一般就労に向けて何か行動されていることはありますか？', 'yesNoRadio'],
    ['activity_detail', '行動詳細', 'textarea'],
    ['other', 'その他事業所に伝えたいこと', 'textarea'],
];

const selfManagementRatingOptions = [
    '毎日頑張れている',
    'まずまず頑張れている',
    'あまり頑張れていない',
    '全く頑張れていない',
];

const yesNoOptions = ['はい', 'いいえ'];

const blankRequestForm = {
    user_id: '',
    type: 'absence',
    request_date: today,
    start_time: '',
    end_time: '',
    reason_category: '私用のため',
    reason: '',
};

const adminTabs = [
    ['attendance', '勤怠一覧'],
    ['requests', '届出'],
    ['reports', '業務報告'],
    ['selfReports', '自己管理レポート'],
    ['profile', 'ユーザー情報'],
    ['order', '並び替え'],
    ['calendar', 'カレンダー管理'],
    ['admins', '管理者一覧'],
    ['messages', 'お知らせ'],
    ['retired', '退職者'],
];

const businessCategoriesByDepartment = {
    新今宮: ['軽作業', '配送その他'],
    日本橋: ['軽作業', '配送その他'],
    南船場: ['物作り', '軽作業その他'],
    阿倍野事務: ['事務'],
    阿倍野弁当: ['弁当', '軽作業その他'],
    在宅: ['在宅事務', '在宅PC', '県外PC', '関東PC', '関西PC'],
    フリーケア: ['マッサージ'],
};

function businessCategoriesForDepartment(department) {
    return businessCategoriesByDepartment[department] ?? businessCategoriesByDepartment.新今宮;
}

function normalizeBusinessCategory(department, businessCategory) {
    const options = businessCategoriesForDepartment(department);

    return options.includes(businessCategory) ? businessCategory : options[0];
}

function RetirementScheduledBadge({ user }) {
    if (!user?.is_retirement_scheduled) return null;

    return (
        <span className="ml-2 inline-flex shrink-0 rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">
            退職予定
        </span>
    );
}

function userProfileDraft(user = {}) {
    const department = user.department ?? '新今宮';

    return {
        name: user.name ?? '',
        hire_date: user.hire_date ?? '',
        retirement_date: user.retirement_date ?? today,
        management_number: user.management_number ?? '',
        email: user.email ?? '',
        workday_settings: user.workday_settings ?? Object.fromEntries(Array.from({ length: 6 }, (_, index) => String(index + 1)).map((weekday) => [
            weekday,
            {
                default_clock_in: user.default_clock_in ?? '09:00',
                default_clock_out: user.default_clock_out ?? '18:00',
                default_break_minutes: user.default_break_minutes ?? 60,
                is_working_day: true,
            },
        ])),
        hourly_wage: user.hourly_wage ?? '',
        department,
        business_category: normalizeBusinessCategory(department, user.business_category ?? ''),
        work_style: user.work_style ?? 'A型',
        commute_limit_days: user.commute_limit_days ?? '-8日',
        paid_leave_remaining_days: user.paid_leave_remaining_days ?? calculateLegalPaidLeaveDays(user.hire_date),
        height_cm: user.height_cm ?? '',
        weight_kg: user.weight_kg ?? '',
        gender: user.gender ?? '男',
    };
}

function calculateLegalPaidLeaveDays(hireDateValue) {
    if (!hireDateValue) return 0;

    const hireDate = new Date(`${hireDateValue}T00:00:00+09:00`);
    if (Number.isNaN(hireDate.getTime())) return 0;

    const asOf = new Date(`${today}T00:00:00+09:00`);
    const expirationThreshold = new Date(asOf);
    expirationThreshold.setFullYear(expirationThreshold.getFullYear() - 2);
    const grantDays = [10, 11, 12, 14, 16, 18];
    let total = 0;

    for (let index = 0; index < 80; index += 1) {
        const grantDate = new Date(hireDate);
        grantDate.setMonth(grantDate.getMonth() + 6 + (index * 12));

        if (grantDate > asOf) break;
        if (grantDate > expirationThreshold) total += grantDays[index] ?? 20;
    }

    return total;
}

const weekdays = [
    ['1', '月'],
    ['2', '火'],
    ['3', '水'],
    ['4', '木'],
    ['5', '金'],
    ['6', '土'],
];

const statusLabels = {
    working: '勤務中',
    completed: '退勤済み',
    not_clocked: '未打刻',
    holiday: '休日',
    absence: '欠勤',
    paid_leave: '有給',
    planned_vacation: '計画有給',
    morning_paid_leave: '前半有給',
    afternoon_paid_leave: '後半有給',
    late: '遅刻',
    early_leave: '早退',
    late_and_early_leave: '遅刻かつ早退',
    business_support: '業務対応',
    free_attendance: '自由出勤日',
};

const statusClasses = {
    working: 'bg-sky-50 text-sky-700 ring-sky-200',
    completed: 'bg-sky-50 text-sky-700 ring-sky-200',
    holiday: 'bg-stone-100 text-stone-700 ring-stone-200',
    absence: 'bg-rose-50 text-rose-700 ring-rose-200',
    paid_leave: 'bg-teal-50 text-teal-700 ring-teal-200',
    planned_vacation: 'bg-teal-50 text-teal-700 ring-teal-200',
    morning_paid_leave: 'bg-teal-50 text-teal-700 ring-teal-200',
    afternoon_paid_leave: 'bg-teal-50 text-teal-700 ring-teal-200',
    late: 'bg-amber-50 text-amber-700 ring-amber-200',
    early_leave: 'bg-violet-50 text-violet-700 ring-violet-200',
    early_leave_planned: 'bg-violet-50 text-violet-700 ring-violet-200',
    early_leave_done: 'bg-indigo-50 text-indigo-700 ring-indigo-200',
    late_and_early_leave: 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200',
    business_support: 'bg-sky-50 text-sky-700 ring-sky-200',
    free_attendance: 'bg-lime-50 text-lime-700 ring-lime-200',
    not_clocked: 'bg-slate-100 text-slate-600 ring-slate-200',
};

const clockMessages = {
    in: '出勤を記録しました。',
    out: '退勤を記録しました。',
};

const requestStatusLabels = {
    pending: '申請中',
    admin_checked: '管理者チェック',
    service_manager_checked: 'サビ管チェック',
};

const requestStatusClasses = {
    pending: 'bg-amber-50 text-amber-700 ring-amber-200',
    admin_checked: 'bg-sky-50 text-sky-700 ring-sky-200',
    service_manager_checked: 'bg-sky-50 text-sky-700 ring-sky-200',
};

function minutesToHours(minutes) {
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    return `${hours}:${String(rest).padStart(2, '0')}`;
}

function shiftMonth(month, amount) {
    const [year, monthNumber] = month.split('-').map(Number);
    const date = new Date(Date.UTC(year, monthNumber - 1 + amount, 1));

    return `${date.getUTCFullYear()}-${String(date.getUTCMonth() + 1).padStart(2, '0')}`;
}

function formatMonthLabel(month) {
    const [year, monthNumber] = month.split('-').map(Number);

    return monthFormatter.format(new Date(Date.UTC(year, monthNumber - 1, 1)));
}

function formatDateWithWeekday(dateValue) {
    if (!dateValue) return '';

    const date = new Date(`${dateValue}T00:00:00+09:00`);
    const weekday = ['日', '月', '火', '水', '木', '金', '土'][date.getDay()];

    return `${dateValue}（${weekday}）`;
}

function buildMonthOptions(baseMonth) {
    return Array.from({ length: 25 }, (_, index) => shiftMonth(baseMonth, index - 12)).reverse();
}

function paginate(items, page, perPage = pageSize) {
    const start = (page - 1) * perPage;

    return items.slice(start, start + perPage);
}

function bootstrapViewerFromDom() {
    const appElement = document.getElementById('app');
    const viewerId = appElement?.dataset.viewerId;

    if (!viewerId) return null;

    return {
        id: Number(viewerId),
        name: appElement.dataset.viewerName ?? '',
        email: appElement.dataset.viewerEmail ?? '',
        role: appElement.dataset.viewerRole ?? '',
        admin_level: appElement.dataset.viewerAdminLevel || null,
        is_admin: appElement.dataset.viewerIsAdmin === 'true',
        is_strong_admin: appElement.dataset.viewerIsStrongAdmin === 'true',
    };
}

const bootstrapViewer = window.__ATTENDANCE_BOOTSTRAP__?.viewer ?? bootstrapViewerFromDom();

function AttendanceApp() {
    const requestedAdminTab = new URLSearchParams(window.location.search).get('active_tab');
    const initialAdminTab = adminTabs.some(([tabKey]) => tabKey === requestedAdminTab) ? requestedAdminTab : 'attendance';
    const [viewer, setViewer] = useState(bootstrapViewer);
    const [users, setUsers] = useState([]);
    const [records, setRecords] = useState([]);
    const [historyRecords, setHistoryRecords] = useState([]);
    const [recordsPage, setRecordsPage] = useState(1);
    const [historyDepartmentPage, setHistoryDepartmentPage] = useState(1);
    const [historyDepartmentTotalUsers, setHistoryDepartmentTotalUsers] = useState(0);
    const [historyRange, setHistoryRange] = useState(null);
    const [historyMode, setHistoryMode] = useState('user');
    const [missingClockOutRecords, setMissingClockOutRecords] = useState([]);
    const [clockStatus, setClockStatus] = useState({ can_clock_in: true, clock_in_disabled_reason: '', can_clock_out: false, clock_out_disabled_reason: '出勤打刻がないため退勤できません。', can_cancel_clock_in: false, can_cancel_clock_out: false });
    const [attendanceRequests, setAttendanceRequests] = useState([]);
    const [attendanceRequestsPage, setAttendanceRequestsPage] = useState(1);
    const [attendanceRequestsTotal, setAttendanceRequestsTotal] = useState(0);
    const [attendanceRequestTypeFilter, setAttendanceRequestTypeFilter] = useState('all');
    const [showAllAttendanceRequests, setShowAllAttendanceRequests] = useState(true);
    const [showAdminAttendanceRequestForm, setShowAdminAttendanceRequestForm] = useState(false);
    const [attendanceRequestSort, setAttendanceRequestSort] = useState({ key: 'request_date', direction: 'desc' });
    const [adminMessages, setAdminMessages] = useState([]);
    const [adminMessageDraft, setAdminMessageDraft] = useState('');
    const [showCollapsedAdminMessages, setShowCollapsedAdminMessages] = useState(false);
    const [adminUsers, setAdminUsers] = useState([]);
    const [isAdminUsersLoading, setIsAdminUsersLoading] = useState(false);
    const [calendarEntries, setCalendarEntries] = useState([]);
    const [calendarCounts, setCalendarCounts] = useState({});
    const [calendarEntryForm, setCalendarEntryForm] = useState(blankCalendarEntryForm);
    const [isCalendarEntriesLoading, setIsCalendarEntriesLoading] = useState(false);
    const [selfManagementReportActiveDate, setSelfManagementReportActiveDate] = useState('');
    const [isSelfManagementReportActive, setIsSelfManagementReportActive] = useState(false);
    const [selfManagementReports, setSelfManagementReports] = useState([]);
    const [selfManagementReportForm, setSelfManagementReportForm] = useState(blankSelfManagementReportForm);
    const [selfManagementAdminCommentDrafts, setSelfManagementAdminCommentDrafts] = useState({});
    const [isSelfManagementReportsLoading, setIsSelfManagementReportsLoading] = useState(false);
    const [todayBusinessReports, setTodayBusinessReports] = useState([]);
    const [monthlyBusinessReports, setMonthlyBusinessReports] = useState([]);
    const [todayBusinessReportsPage, setTodayBusinessReportsPage] = useState(1);
    const [monthlyBusinessReportsPage, setMonthlyBusinessReportsPage] = useState(1);
    const [isBusinessReportsLoading, setIsBusinessReportsLoading] = useState(false);
    const [retiredUsers, setRetiredUsers] = useState([]);
    const [selectedRetiredUserId, setSelectedRetiredUserId] = useState('');
    const [retiredRecords, setRetiredRecords] = useState([]);
    const [retiredRequests, setRetiredRequests] = useState([]);
    const [isRetiredUsersLoading, setIsRetiredUsersLoading] = useState(false);
    const [summary, setSummary] = useState(null);
    const [calendarHighlights, setCalendarHighlights] = useState({ saturday_work: [], holiday_off: [] });
    const [selectedUser, setSelectedUser] = useState(new URLSearchParams(window.location.search).get('user_id') ?? '');
    const [selectedHistoryDepartment, setSelectedHistoryDepartment] = useState(new URLSearchParams(window.location.search).get('department') ?? '');
    const [userSearch, setUserSearch] = useState('');
    const [month, setMonth] = useState(new URLSearchParams(window.location.search).get('month') ?? currentMonth);
    const [displayDate, setDisplayDate] = useState(new URLSearchParams(window.location.search).get('date') ?? today);
    const [form, setForm] = useState(blankForm);
    const [clockOutDraft, setClockOutDraft] = useState({ declared_clock_in: '', declared_clock_out: '', declared_break_minutes: '', work_location: 'office', meal_percentage: '', missed_meal: false });
    const [showClockOutModal, setShowClockOutModal] = useState(false);
    const [showAttendanceRecordForm, setShowAttendanceRecordForm] = useState(false);
    const [activeAdminTab, setActiveAdminTab] = useState(initialAdminTab);
    const [draggingOrderUserId, setDraggingOrderUserId] = useState(null);
    const [draggingOrderDepartment, setDraggingOrderDepartment] = useState(null);
    const [requestForm, setRequestForm] = useState(blankRequestForm);
    const [isLoading, setIsLoading] = useState(true);
    const [isHistoryLoading, setIsHistoryLoading] = useState(false);
    const [hasCompletedInitialLoad, setHasCompletedInitialLoad] = useState(false);
    const [initialLoadError, setInitialLoadError] = useState('');
    const [message, setMessage] = useState('');
    const [messageTone, setMessageTone] = useState('neutral');
    const [userProfileMessage, setUserProfileMessage] = useState('');
    const [errors, setErrors] = useState({});
    const [businessReportDrafts, setBusinessReportDrafts] = useState({});
    const [userProfileDrafts, setUserProfileDrafts] = useState({});
    const reportSaveTimers = useRef({});
    const recordsRefreshInFlight = useRef(false);
    const targetUserListRef = useRef(null);
    const selectedTargetUserButtonRef = useRef(null);
    const departmentHistoryScrollRef = useRef(null);
    const departmentHistoryBottomScrollRef = useRef(null);
    const isSyncingDepartmentHistoryScroll = useRef(false);
    const [departmentHistoryScrollWidth, setDepartmentHistoryScrollWidth] = useState(0);

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
    const isViewerResolved = viewer && typeof viewer.is_admin === 'boolean';
    const isAdmin = isViewerResolved ? viewer.is_admin : false;
    const isStrongAdmin = Boolean(viewer?.is_strong_admin);
    const visibleAdminTabs = useMemo(
        () => adminTabs.filter(([tabKey]) => {
            if (!isStrongAdmin && tabKey === 'profile') return false;

            return true;
        }),
        [isStrongAdmin],
    );
    const isHistoryPage = window.location.pathname === '/attendance-history';
    const isBusinessReportHistoryPage = window.location.pathname === '/business-report-history';
    const isStandaloneAdminPage = isHistoryPage || isBusinessReportHistoryPage;
    const showDepartmentHistoryScrollbar = isHistoryPage && isAdmin && historyMode === 'department';

    useEffect(() => {
        if (!showDepartmentHistoryScrollbar) {
            setDepartmentHistoryScrollWidth(0);

            return undefined;
        }

        const scrollElement = departmentHistoryScrollRef.current;
        if (!scrollElement) return undefined;

        const updateScrollWidth = () => {
            setDepartmentHistoryScrollWidth(scrollElement.scrollWidth);
            if (departmentHistoryBottomScrollRef.current) {
                departmentHistoryBottomScrollRef.current.scrollLeft = scrollElement.scrollLeft;
            }
        };

        updateScrollWidth();
        window.addEventListener('resize', updateScrollWidth);

        const resizeObserver = window.ResizeObserver ? new ResizeObserver(updateScrollWidth) : null;
        resizeObserver?.observe(scrollElement);
        if (scrollElement.firstElementChild) {
            resizeObserver?.observe(scrollElement.firstElementChild);
        }

        return () => {
            window.removeEventListener('resize', updateScrollWidth);
            resizeObserver?.disconnect();
        };
    }, [showDepartmentHistoryScrollbar, historyRecords.length, historyDepartmentPage, month, isHistoryLoading]);

    useEffect(() => {
        loadRecords();
    }, [selectedUser, month, displayDate]);

    useEffect(() => {
        setAttendanceRequestsPage(1);
    }, [selectedUser, month, showAllAttendanceRequests, attendanceRequestTypeFilter]);

    useEffect(() => {
        if (isAdmin && !isStrongAdmin && activeAdminTab === 'profile') {
            setActiveAdminTab('attendance');
        }
    }, [isAdmin, isStrongAdmin, activeAdminTab]);

    useEffect(() => {
        if (!viewer) return;
        if (!hasCompletedInitialLoad) return;
        if (isStandaloneAdminPage) return;

        loadSelfManagementReports();
    }, [viewer?.id, hasCompletedInitialLoad, isStandaloneAdminPage]);

    useEffect(() => {
        if (!viewer) return;
        if (!hasCompletedInitialLoad) return;

        if (!viewer.is_admin) {
            loadAttendanceRequests();
            loadAdminMessages();
            loadSelfManagementReports();
            return;
        }

        if (isHistoryPage) {
            loadHistoryRecords(selectedUser, selectedHistoryDepartment, selectedHistoryDepartment ? historyDepartmentPage : 1);
            return;
        }

        if (isBusinessReportHistoryPage) {
            loadBusinessReports(selectedUser);
            return;
        }

        if (activeAdminTab === 'requests') {
            loadAttendanceRequests();
        }

        if (activeAdminTab === 'messages') {
            loadAdminMessages();
        }

        if (activeAdminTab === 'reports') {
            loadBusinessReports(selectedUser);
        }

        if (activeAdminTab === 'admins') {
            loadAdminUsers();
        }

        if (activeAdminTab === 'calendar') {
            loadCalendarEntries();
        }

        if (activeAdminTab === 'selfReports') {
            loadSelfManagementReports();
        }

        if (activeAdminTab === 'retired') {
            loadRetiredUsers();
        }
    }, [viewer?.id, viewer?.is_admin, hasCompletedInitialLoad, activeAdminTab, selectedUser, selectedHistoryDepartment, historyDepartmentPage, month, showAllAttendanceRequests, attendanceRequestsPage, attendanceRequestTypeFilter, isHistoryPage, isBusinessReportHistoryPage]);

    useEffect(() => {
        if (!hasCompletedInitialLoad) return undefined;
        if (isStandaloneAdminPage) return undefined;
        if (isAdmin && activeAdminTab !== 'attendance') return undefined;

        const intervalId = setInterval(() => {
            refreshAttendanceRecords();
        }, 5000);

        return () => clearInterval(intervalId);
    }, [hasCompletedInitialLoad, isStandaloneAdminPage, isAdmin, activeAdminTab, selectedUser, month, displayDate]);

    useEffect(() => {
        setRecordsPage(1);
        setHistoryDepartmentPage(1);
        setTodayBusinessReportsPage(1);
        setMonthlyBusinessReportsPage(1);
    }, [selectedUser, selectedHistoryDepartment, month, displayDate]);

    useEffect(() => {
        if (!selectedUser) return;

        if (isHistoryPage) {
            const params = new URLSearchParams({ month });
            if (selectedHistoryDepartment) {
                params.set('department', selectedHistoryDepartment);
            } else {
                params.set('user_id', selectedUser);
            }
            window.history.replaceState(null, '', `/attendance-history?${params.toString()}`);
        }

        if (isBusinessReportHistoryPage) {
            window.history.replaceState(null, '', `/business-report-history?user_id=${selectedUser}&month=${month}`);
        }
    }, [isBusinessReportHistoryPage, isHistoryPage, selectedUser, selectedHistoryDepartment, month]);

    useEffect(() => {
        return () => {
            Object.values(reportSaveTimers.current).forEach(clearTimeout);
        };
    }, []);

    useEffect(() => {
        setRecordsPage((current) => Math.min(current, Math.max(1, Math.ceil(records.length / pageSize))));
    }, [records.length]);

    useEffect(() => {
        setTodayBusinessReportsPage((current) => Math.min(current, Math.max(1, Math.ceil(todayBusinessReports.length / pageSize))));
    }, [todayBusinessReports.length]);

    useEffect(() => {
        setMonthlyBusinessReportsPage((current) => Math.min(current, Math.max(1, Math.ceil(monthlyBusinessReports.length / pageSize))));
    }, [monthlyBusinessReports.length]);

    useEffect(() => {
        const fallbackUserId = selectedUser || viewer?.id || users[0]?.id || '';

        if (!form.user_id && fallbackUserId) {
            setForm((current) => ({ ...current, user_id: String(fallbackUserId) }));
        }
    }, [users, viewer, selectedUser, form.user_id]);

    async function request(path, options = {}) {
        const response = await fetch(path, {
            cache: 'no-store',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                ...options.headers,
            },
            ...options,
        });

        if (response.status === 401) {
            window.location.href = '/login';
            throw new Error('ログインが必要です。');
        }

        if (response.status === 403) {
            throw new Error('この勤怠は修正できません。直近3日以内の勤怠のみ修正できます。');
        }

        if (response.status === 422) {
            const payload = await response.json();
            setErrors(payload.errors ?? {});
            throw new Error('入力内容を確認してください。');
        }

        if (!response.ok) {
            throw new Error('処理に失敗しました。時間をおいて再度お試しください。');
        }

        return response.status === 204 ? null : response.json();
    }

    async function requestWithRetry(path, options = {}, retries = 2) {
        let lastError;

        for (let attempt = 0; attempt <= retries; attempt += 1) {
            try {
                return await request(path, options);
            } catch (error) {
                lastError = error;
                if (attempt === retries || !String(error.message).includes('fetch')) {
                    throw error;
                }
                await new Promise((resolve) => setTimeout(resolve, 800));
            }
        }

        throw lastError;
    }

    async function loadRecords() {
        setIsLoading(true);
        setInitialLoadError('');
        setErrors({});

        const params = new URLSearchParams({ month });
        if (selectedUser) params.set('user_id', selectedUser);
        if (displayDate) params.set('date', displayDate);

        try {
            const data = await requestWithRetry(`/api/attendance-records?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            setViewer(data.viewer);
            setUsers(data.users);
            setRecords(data.records);
            setMissingClockOutRecords(data.missing_clock_out_records ?? []);
            setClockStatus(data.clock ?? { can_clock_in: true, clock_in_disabled_reason: '', can_clock_out: false, clock_out_disabled_reason: '出勤打刻がないため退勤できません。', can_cancel_clock_in: false, can_cancel_clock_out: false });
            setSummary(data.summary);
            setCalendarHighlights(data.calendar_highlights ?? { saturday_work: [], holiday_off: [] });
            setUserProfileDrafts(Object.fromEntries(data.users.map((user) => [
                user.id,
                userProfileDraft(user),
            ])));
            const selectedUserIsActive = data.users.some((user) => String(user.id) === String(selectedUser));
            const nextSelectedUser = selectedUserIsActive ? selectedUser : String(data.selected_user_id ?? '');
            if (nextSelectedUser && selectedUser !== nextSelectedUser) {
                setSelectedUser(nextSelectedUser);
                setUserSearch(data.users.find((user) => String(user.id) === String(nextSelectedUser))?.name ?? '');
            }

            setForm((current) => ({
                ...current,
                user_id: current.id ? current.user_id : nextSelectedUser,
            }));
            setRequestForm((current) => ({
                ...current,
                user_id: nextSelectedUser,
            }));
        } catch (error) {
            if (!viewer) {
                setInitialLoadError(error.message);
            } else {
                setMessage(error.message);
                setMessageTone('error');
            }
        } finally {
            setIsLoading(false);
            setHasCompletedInitialLoad(true);
        }
    }

    async function loadAttendanceRequests(userId = selectedUser, page = attendanceRequestsPage) {
        if (!viewer) return;

        const requestParams = new URLSearchParams({ month });
        requestParams.set('page', String(page));
        if (attendanceRequestTypeFilter !== 'all') {
            requestParams.set('type', attendanceRequestTypeFilter);
        }
        if (viewer.is_admin) {
            if (showAllAttendanceRequests) {
                requestParams.set('all_users', '1');
            } else if (userId) {
                requestParams.set('user_id', userId);
            }
        }

        const requestData = await request(`/api/attendance-requests?${requestParams.toString()}`, {
            headers: { 'Content-Type': 'application/json' },
        });
        setAttendanceRequests(requestData.requests);
        setAttendanceRequestsTotal(requestData.pagination?.total ?? requestData.requests.length);
        if ((requestData.requests?.length ?? 0) === 0 && page > 1) {
            setAttendanceRequestsPage(page - 1);
        }
    }

    async function loadAdminMessages() {
        const messageData = await request('/api/admin-messages', {
            headers: { 'Content-Type': 'application/json' },
        });
        setAdminMessages(messageData.messages);
    }

    async function loadAdminUsers() {
        setIsAdminUsersLoading(true);

        try {
            const data = await request('/api/admins', {
                headers: { 'Content-Type': 'application/json' },
            });
            setAdminUsers(data.admins ?? []);
        } finally {
            setIsAdminUsersLoading(false);
        }
    }

    async function updateAdminLevel(adminUser, adminLevel) {
        if (!isStrongAdmin) return;

        setMessage('');
        setMessageTone('neutral');

        try {
            const updatedAdmin = await request(`/api/admins/${adminUser.id}`, {
                method: 'PATCH',
                body: JSON.stringify({ admin_level: adminLevel }),
            });

            setAdminUsers((currentAdmins) => currentAdmins.map((currentAdmin) => (
                currentAdmin.id === updatedAdmin.id ? updatedAdmin : currentAdmin
            )));
            setMessage('管理者権限を更新しました。');
            setMessageTone('success');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
            await loadAdminUsers();
        }
    }

    async function loadCalendarEntries() {
        setIsCalendarEntriesLoading(true);

        try {
            const data = await request(`/api/calendar-entries?month=${month}`, {
                headers: { 'Content-Type': 'application/json' },
            });
            setCalendarEntries(data.entries ?? []);
            setCalendarCounts(data.counts ?? {});
        } finally {
            setIsCalendarEntriesLoading(false);
        }
    }

    async function submitCalendarEntry(event) {
        event.preventDefault();
        setMessage('');
        setMessageTone('neutral');

        try {
            await request('/api/calendar-entries', {
                method: 'POST',
                body: JSON.stringify(calendarEntryForm),
            });
            const entryMonth = calendarEntryForm.date.slice(0, 7);
            setCalendarEntryForm((current) => ({
                ...blankCalendarEntryForm,
                date: current.date,
                type: current.type,
            }));
            setMessage('カレンダー項目を追加しました。');
            setMessageTone('success');

            if (entryMonth !== month) {
                setMonth(entryMonth);
            } else {
                await loadCalendarEntries();
            }
            if (calendarEntryForm.type === 'self_report_due') {
                await loadSelfManagementReports();
            }
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function deleteCalendarEntry(entry) {
        if (!window.confirm(`${entry.date} の「${entry.type_label}」を削除しますか？`)) return;

        setMessage('');
        setMessageTone('neutral');

        try {
            await request(`/api/calendar-entries/${entry.id}`, {
                method: 'DELETE',
            });
            setMessage('カレンダー項目を削除しました。');
            setMessageTone('success');
            await loadCalendarEntries();
            if (entry.type === 'self_report_due') {
                await loadSelfManagementReports();
            }
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function loadSelfManagementReports(reportDate = '') {
        setIsSelfManagementReportsLoading(true);

        try {
            const params = new URLSearchParams();
            if (reportDate) params.set('report_date', reportDate);

            const data = await request(`/api/self-management-reports?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            const selectedDate = data.selected_report_date ?? '';
            setSelfManagementReportActiveDate(selectedDate);
            setIsSelfManagementReportActive(Boolean(data.active));

            if (viewer?.is_admin) {
                const reports = data.reports ?? [];
                setSelfManagementReports(reports);
                setSelfManagementAdminCommentDrafts(Object.fromEntries(reports.map((report) => [
                    report.id,
                    report.admin_comment ?? '',
                ]).filter(([id]) => id)));
            } else {
                const report = data.report ?? {};
                setSelfManagementReportForm({
                    ...blankSelfManagementReportForm,
                    ...report,
                    report_date: selectedDate,
                });
            }
        } finally {
            setIsSelfManagementReportsLoading(false);
        }
    }

    async function submitSelfManagementReport(event) {
        event.preventDefault();
        setMessage('');
        setMessageTone('neutral');
        setErrors({});

        try {
            const report = await request('/api/self-management-reports', {
                method: 'POST',
                body: JSON.stringify({
                    ...selfManagementReportForm,
                    report_date: selfManagementReportActiveDate,
                }),
            });

            setSelfManagementReportForm({
                ...blankSelfManagementReportForm,
                ...report,
            });
            setMessage('自己管理レポートを提出しました。');
            setMessageTone('success');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function saveSelfManagementAdminComment(report) {
        if (!report.id) return;

        setMessage('');
        setMessageTone('neutral');

        try {
            const updatedReport = await request(`/api/self-management-reports/${report.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    admin_comment: selfManagementAdminCommentDrafts[report.id] ?? '',
                }),
            });

            setSelfManagementReports((currentReports) => currentReports.map((currentReport) => (
                currentReport.id === updatedReport.id
                    ? { ...currentReport, admin_comment: updatedReport.admin_comment }
                    : currentReport
            )));
            setMessage('管理者コメントを保存しました。');
            setMessageTone('success');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function loadRetiredUsers(userId = selectedRetiredUserId) {
        setIsRetiredUsersLoading(true);

        try {
            const params = new URLSearchParams();
            if (userId) params.set('user_id', userId);

            const data = await request(`/api/retired-users?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            setRetiredUsers(data.users);
            setSelectedRetiredUserId(data.selected_user_id ? String(data.selected_user_id) : '');
            setRetiredRecords(data.records ?? []);
            setRetiredRequests(data.requests ?? []);
        } finally {
            setIsRetiredUsersLoading(false);
        }
    }

    async function refreshAttendanceRecords() {
        if (recordsRefreshInFlight.current) return;

        recordsRefreshInFlight.current = true;

        const params = new URLSearchParams({ month });
        if (selectedUser) params.set('user_id', selectedUser);
        if (displayDate) params.set('date', displayDate);
        params.set('_refresh', String(Date.now()));

        try {
            const data = await request(`/api/attendance-records?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            setRecords(data.records);
            setMissingClockOutRecords(data.missing_clock_out_records ?? []);
            setClockStatus(data.clock ?? { can_clock_in: true, clock_in_disabled_reason: '', can_clock_out: false, clock_out_disabled_reason: '出勤打刻がないため退勤できません。', can_cancel_clock_in: false, can_cancel_clock_out: false });
            setSummary(data.summary);
            setCalendarHighlights(data.calendar_highlights ?? { saturday_work: [], holiday_off: [] });
        } catch (error) {
            console.error(error);
        } finally {
            recordsRefreshInFlight.current = false;
        }
    }

    async function loadHistoryRecords(userId = selectedUser, department = selectedHistoryDepartment, page = historyDepartmentPage) {
        if (!userId && !department) return;

        setIsHistoryLoading(true);

        try {
            const params = new URLSearchParams({ month });
            if (department) {
                params.set('department', department);
                params.set('page', String(page));
                params.set('per_page', String(historyDepartmentPageSize));
            } else {
                params.set('user_id', userId);
            }
            if (displayDate) params.set('date', displayDate);
            const data = await request(`/api/attendance-records/history?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            setHistoryRecords(data.records);
            setHistoryMode(data.mode ?? (department ? 'department' : 'user'));
            setHistoryRange({ month: data.month, start: data.start_date, end: data.end_date });
            setHistoryDepartmentTotalUsers(data.pagination?.total_users ?? (department ? data.users?.length ?? 0 : 0));
        } finally {
            setIsHistoryLoading(false);
        }
    }

    async function loadBusinessReports(userId = selectedUser) {
        if (!userId) return;

        setIsBusinessReportsLoading(true);

        try {
            const params = new URLSearchParams({ user_id: userId, month });
            const data = await request(`/api/attendance-records/business-reports?${params.toString()}`, {
                headers: { 'Content-Type': 'application/json' },
            });

            setTodayBusinessReports(data.today_reports);
            setMonthlyBusinessReports(data.monthly_reports);
        } finally {
            setIsBusinessReportsLoading(false);
        }
    }

    async function submitAttendanceRequest(event) {
        event.preventDefault();
        setMessage('');
        setMessageTone('neutral');
        setErrors({});
        setActiveAdminTab('requests');

        const userId = isAdmin ? selectedUser : viewer?.id;

        try {
            await request('/api/attendance-requests', {
                method: 'POST',
                body: JSON.stringify({
                    ...requestForm,
                    user_id: Number(userId),
                    start_time: requestTypeHidesStartTime ? '' : requestForm.start_time,
                    end_time: requestTypeHidesEndTime ? '' : requestForm.end_time,
                }),
            });
            setMessage('届出を送信しました。');
            setMessageTone('success');
            setRequestForm({ ...blankRequestForm, user_id: selectedUser || viewer?.id || '' });
            setAttendanceRequestsPage(1);
            await loadAttendanceRequests(String(userId), 1);
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
        }
    }

    async function updateAttendanceRequestChecks(attendanceRequest, field, checked) {
        if (!isAdmin) return;

        const nextRequest = {
            ...attendanceRequest,
            [field]: checked,
        };

        setAttendanceRequests((currentRequests) => currentRequests.map((currentRequest) => (
            currentRequest.id === attendanceRequest.id ? nextRequest : currentRequest
        )));

        try {
            const updatedRequest = await request(`/api/attendance-requests/${attendanceRequest.id}/checks`, {
                method: 'PATCH',
                body: JSON.stringify({
                    admin_checked: Boolean(nextRequest.admin_checked),
                    service_manager_checked: Boolean(nextRequest.service_manager_checked),
                }),
            });

            setAttendanceRequests((currentRequests) => currentRequests.map((currentRequest) => (
                currentRequest.id === updatedRequest.id ? updatedRequest : currentRequest
            )));
        } catch (error) {
            setAttendanceRequests((currentRequests) => currentRequests.map((currentRequest) => (
                currentRequest.id === attendanceRequest.id ? attendanceRequest : currentRequest
            )));
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function updateFormAttendanceRequestCheck(attendanceRequest, field, checked) {
        if (!isAdmin) return;

        const nextRequest = {
            ...attendanceRequest,
            [field]: checked,
        };
        const applyNextRequest = (currentRequest) => (
            currentRequest.id === attendanceRequest.id ? { ...currentRequest, ...nextRequest } : currentRequest
        );

        setForm((currentForm) => ({
            ...currentForm,
            attendance_requests: (currentForm.attendance_requests ?? []).map(applyNextRequest),
        }));

        try {
            const updatedRequest = await request(`/api/attendance-requests/${attendanceRequest.id}/checks`, {
                method: 'PATCH',
                body: JSON.stringify({
                    admin_checked: Boolean(nextRequest.admin_checked),
                    service_manager_checked: Boolean(nextRequest.service_manager_checked),
                }),
            });
            const applyUpdatedRequest = (currentRequest) => (
                currentRequest.id === updatedRequest.id ? { ...currentRequest, ...updatedRequest } : currentRequest
            );

            setForm((currentForm) => ({
                ...currentForm,
                attendance_requests: (currentForm.attendance_requests ?? []).map(applyUpdatedRequest),
            }));
            setAttendanceRequests((currentRequests) => currentRequests.map(applyUpdatedRequest));
            setRecords((currentRecords) => currentRecords.map((currentRecord) => ({
                ...currentRecord,
                attendance_requests: (currentRecord.attendance_requests ?? []).map(applyUpdatedRequest),
            })));
            setHistoryRecords((currentRecords) => currentRecords.map((currentRecord) => ({
                ...currentRecord,
                attendance_requests: (currentRecord.attendance_requests ?? []).map(applyUpdatedRequest),
            })));
            setMessage('届出チェックを更新しました。');
            setMessageTone('success');
        } catch (error) {
            setForm((currentForm) => ({
                ...currentForm,
                attendance_requests: (currentForm.attendance_requests ?? []).map((currentRequest) => (
                    currentRequest.id === attendanceRequest.id ? attendanceRequest : currentRequest
                )),
            }));
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function deleteAttendanceRequest(attendanceRequest) {
        if (!window.confirm('この届出を削除しますか？')) return;

        setMessage('');
        setMessageTone('neutral');

        try {
            await request(`/api/attendance-requests/${attendanceRequest.id}`, {
                method: 'DELETE',
            });

            setAttendanceRequests((currentRequests) => currentRequests.filter((currentRequest) => (
                currentRequest.id !== attendanceRequest.id
            )));
            setMessage('届出を削除しました。');
            setMessageTone('success');
            await loadAttendanceRequests();
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function submitAdminMessage(event) {
        event.preventDefault();
        if (!isAdmin) return;

        setMessage('');
        setMessageTone('neutral');
        setErrors({});

        try {
            const newMessage = await request('/api/admin-messages', {
                method: 'POST',
                body: JSON.stringify({
                    body: adminMessageDraft,
                }),
            });

            setAdminMessages((currentMessages) => [newMessage, ...currentMessages].slice(0, 10));
            setAdminMessageDraft('');
            setMessage('全員へお知らせを送信しました。');
            setMessageTone('success');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function saveUserProfile() {
        if (!isStrongAdmin) return;

        const user = users.find((currentUser) => String(currentUser.id) === String(selectedUser));
        if (!user) return;

        const draft = userProfileDrafts[user.id];
        if (!draft) return;

        setMessage('');
        setMessageTone('neutral');
        setUserProfileMessage('');
        setErrors({});

        try {
            const updatedUser = await request(`/api/users/${user.id}/profile`, {
                method: 'PUT',
                body: JSON.stringify({
                    ...draft,
                    workday_settings: Object.fromEntries(Object.entries(draft.workday_settings).map(([weekday, setting]) => [
                        weekday,
                        {
                            default_clock_in: setting.default_clock_in,
                            default_clock_out: setting.default_clock_out,
                            default_break_minutes: Number(setting.default_break_minutes),
                            is_working_day: Boolean(setting.is_working_day),
                        },
                    ])),
                    hourly_wage: draft.hourly_wage === '' ? null : Number(draft.hourly_wage),
                    commute_limit_days: draft.commute_limit_days,
                    paid_leave_remaining_days: draft.paid_leave_remaining_days === '' ? null : Number(draft.paid_leave_remaining_days),
                    height_cm: draft.height_cm === '' ? null : Number(draft.height_cm),
                    weight_kg: draft.weight_kg === '' ? null : Number(draft.weight_kg),
                }),
            });

            setUsers((currentUsers) => currentUsers.map((currentUser) => (
                currentUser.id === updatedUser.id ? updatedUser : currentUser
            )));
            setUserProfileDrafts((currentDrafts) => ({
                ...currentDrafts,
                [updatedUser.id]: userProfileDraft(updatedUser),
            }));
            setUserProfileMessage('ユーザー情報を編集しました');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function autoSaveUserDisplayOrder(nextUsers) {
        try {
            await request('/api/users/display-order', {
                method: 'PUT',
                body: JSON.stringify({
                    orders: nextUsers.map((user) => ({
                        user_id: user.id,
                        display_order: Number(user.display_order ?? 0),
                        department_display_order: Number(user.department_display_order ?? 0),
                        department: user.department || '未設定',
                    })),
                }),
            });
            setMessage('表示順を自動保存しました。');
            setMessageTone('success');
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    function reorderDepartmentUsers(targetDepartment, draggedUserId, targetUserId = null) {
        if (!draggedUserId) {
            setDraggingOrderUserId(null);
            return;
        }

        const draggedUser = users.find((user) => String(user.id) === String(draggedUserId));

        if (!draggedUser) {
            setDraggingOrderUserId(null);
            return;
        }

        const sourceDepartment = draggedUser.department || '未設定';
        const movingAcrossDepartments = sourceDepartment !== targetDepartment;
        if (!movingAcrossDepartments && targetUserId && String(draggedUserId) === String(targetUserId)) {
            setDraggingOrderUserId(null);
            return;
        }

        const departments = usersByDepartment.map(([department]) => department);
        const nextUsersByDepartment = new Map(usersByDepartment.map(([department, departmentUsers]) => [
            department,
            departmentUsers.filter((user) => String(user.id) !== String(draggedUserId)),
        ]));
        const targetUsers = nextUsersByDepartment.get(targetDepartment) ?? [];
        const targetIndex = targetUserId
            ? targetUsers.findIndex((user) => String(user.id) === String(targetUserId))
            : targetUsers.length;
        const insertIndex = targetIndex < 0 ? targetUsers.length : targetIndex;
        targetUsers.splice(insertIndex, 0, { ...draggedUser, department: targetDepartment });
        nextUsersByDepartment.set(targetDepartment, targetUsers);

        const nextUsers = departments.flatMap((department, departmentIndex) => (
            (nextUsersByDepartment.get(department) ?? []).map((user, index) => ({
                ...user,
                display_order: (index + 1) * 10,
                department_display_order: (departmentIndex + 1) * 10,
            }))
        ));

        setUsers(nextUsers);
        setUserProfileDrafts((currentDrafts) => {
            const nextDrafts = { ...currentDrafts };
            nextUsers.forEach((user) => {
                if (nextDrafts[user.id]) {
                    nextDrafts[user.id] = {
                        ...nextDrafts[user.id],
                        department: user.department,
                        business_category: normalizeBusinessCategory(user.department, nextDrafts[user.id].business_category),
                    };
                }
            });

            return nextDrafts;
        });
        setDraggingOrderUserId(null);
        setDraggingOrderDepartment(null);
        autoSaveUserDisplayOrder(nextUsers);
    }

    function reorderDepartments(draggedDepartment, targetDepartment) {
        if (!draggedDepartment || !targetDepartment || draggedDepartment === targetDepartment) {
            setDraggingOrderDepartment(null);
            return;
        }

        const departments = usersByDepartment.map(([department]) => department);
        const nextDepartments = departments.filter((department) => department !== draggedDepartment);
        const targetIndex = nextDepartments.indexOf(targetDepartment);
        nextDepartments.splice(targetIndex < 0 ? nextDepartments.length : targetIndex, 0, draggedDepartment);

        const usersByDepartmentMap = new Map(usersByDepartment);
        const nextUsers = nextDepartments.flatMap((department, departmentIndex) => (
            (usersByDepartmentMap.get(department) ?? []).map((user, index) => ({
                ...user,
                display_order: (index + 1) * 10,
                department_display_order: (departmentIndex + 1) * 10,
            }))
        ));

        setUsers(nextUsers);
        setDraggingOrderUserId(null);
        setDraggingOrderDepartment(null);
        autoSaveUserDisplayOrder(nextUsers);
    }

    async function retireSelectedUser() {
        const user = users.find((currentUser) => String(currentUser.id) === String(selectedUser));
        const draft = user ? userProfileDrafts[user.id] : null;
        if (!user || !draft) return;

        if (!draft.retirement_date) {
            setUserProfileMessage('退職予定日を選択してください');
            return;
        }

        if (!window.confirm(`${user.name} さんを退職扱いにしますか？`)) return;

        setMessage('');
        setMessageTone('neutral');
        setUserProfileMessage('');

        try {
            const updatedUser = await request(`/api/users/${user.id}/retire`, {
                method: 'POST',
                body: JSON.stringify({ retirement_date: draft.retirement_date }),
            });
            setUserProfileMessage('退職扱いにしました');
            setActiveAdminTab(updatedUser.is_retirement_scheduled ? 'profile' : 'retired');
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function cancelSelectedUserRetirement() {
        const user = users.find((currentUser) => String(currentUser.id) === String(selectedUser));
        if (!user || !window.confirm(`${user.name} さんの退職予定をキャンセルしますか？`)) return;

        setMessage('');
        setMessageTone('neutral');
        setUserProfileMessage('');

        try {
            await request(`/api/users/${user.id}/restore`, { method: 'POST' });
            setUserProfileMessage('退職予定をキャンセルしました');
            setActiveAdminTab('profile');
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function selectRetiredUser(userId) {
        setActiveAdminTab('retired');
        setSelectedRetiredUserId(String(userId));
        await loadRetiredUsers(String(userId));
    }

    async function restoreRetiredUser() {
        if (!selectedRetiredUserId) return;
        const user = retiredUsers.find((currentUser) => String(currentUser.id) === String(selectedRetiredUserId));
        if (!user || !window.confirm(`${user.name} さんを復職させますか？`)) return;

        try {
            await request(`/api/users/${user.id}/restore`, { method: 'POST' });
            setMessage('復職しました。');
            setMessageTone('success');
            await loadRecords();
            await loadRetiredUsers('');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function forceDeleteRetiredUser() {
        if (!selectedRetiredUserId) return;
        const user = retiredUsers.find((currentUser) => String(currentUser.id) === String(selectedRetiredUserId));
        if (!user) return;

        if (!window.confirm(`${user.name} さんをDBから完全に削除します。勤怠・届出も削除されます。実行しますか？`)) return;
        if (!window.confirm('この操作は元に戻せません。本当に完全削除しますか？')) return;

        try {
            await request(`/api/users/${user.id}/force-delete`, { method: 'DELETE' });
            setMessage('退職者をDBから完全に削除しました。');
            setMessageTone('success');
            await loadRetiredUsers('');
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
        }
    }

    async function submitRecord(event) {
        event.preventDefault();
        setMessage('');
        setMessageTone('neutral');
        setErrors({});

        const userId = isAdmin ? form.user_id : viewer?.id;
        const payload = {
            ...form,
            user_id: Number(userId),
            declared_break_minutes: form.declared_break_minutes === '' || form.declared_break_minutes === null ? null : Number(form.declared_break_minutes),
            meal_percentage: form.work_location === 'home' || form.meal_percentage === '' || form.meal_percentage === null ? null : Number(form.meal_percentage),
            missed_meal: form.work_location === 'home' ? false : Boolean(form.missed_meal),
            break_minutes: Number(form.break_minutes),
        };
        delete payload.attendance_requests;
        if (!attendanceRequestLinkedStatuses.includes(form.status)) {
            delete payload.request_reason_category;
            delete payload.request_reason;
            delete payload.request_absent_start_time;
            delete payload.request_absent_end_time;
            delete payload.request_late_start_time;
            delete payload.request_late_end_time;
            delete payload.request_early_leave_start_time;
            delete payload.request_early_leave_end_time;
        } else if (!['late', 'early_leave', 'late_and_early_leave'].includes(form.status)) {
            delete payload.request_absent_start_time;
            delete payload.request_absent_end_time;
            delete payload.request_late_start_time;
            delete payload.request_late_end_time;
            delete payload.request_early_leave_start_time;
            delete payload.request_early_leave_end_time;
        } else if (form.status !== 'late_and_early_leave') {
            delete payload.request_late_start_time;
            delete payload.request_late_end_time;
            delete payload.request_early_leave_start_time;
            delete payload.request_early_leave_end_time;
        } else {
            delete payload.request_absent_start_time;
            delete payload.request_absent_end_time;
        }
        const path = form.id ? `/api/attendance-records/${form.id}` : '/api/attendance-records';
        const method = form.id ? 'PUT' : 'POST';

        try {
            await request(path, { method, body: JSON.stringify(payload) });
            setMessage('勤怠を更新しました。');
            setMessageTone('success');
            resetForm();
            if (isHistoryPage) {
                await loadHistoryRecords(userId, selectedHistoryDepartment, selectedHistoryDepartment ? historyDepartmentPage : 1);
            } else {
                await loadRecords();
            }
        } catch (error) {
            setMessage(error.message);
        }
    }

    async function clock(type, declaredTimes = {}) {
        setMessage('');
        setMessageTone('neutral');
        setErrors({});

        try {
            const payloadDeclaredTimes = {
                ...declaredTimes,
                meal_percentage: declaredTimes.work_location === 'home' || declaredTimes.meal_percentage === '' || declaredTimes.meal_percentage === null ? null : Number(declaredTimes.meal_percentage),
                missed_meal: declaredTimes.work_location === 'home' ? false : Boolean(declaredTimes.missed_meal),
            };
            await request('/api/attendance-records/clock', {
                method: 'POST',
                body: JSON.stringify({ user_id: Number(selectedUser || viewer?.id), type, ...payloadDeclaredTimes }),
            });
            setMessage(clockMessages[type]);
            setMessageTone('success');
            setShowClockOutModal(false);
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
        }
    }

    async function cancelClock(type) {
        setMessage('');
        setMessageTone('neutral');
        setErrors({});

        try {
            await request('/api/attendance-records/clock/cancel', {
                method: 'POST',
                body: JSON.stringify({ user_id: Number(selectedUser || viewer?.id), type }),
            });
            setMessage(type === 'in' ? '出勤打刻を取り消しました。' : '退勤打刻を取り消しました。');
            setMessageTone('success');
            await loadRecords();
        } catch (error) {
            setMessage(error.message);
            setMessageTone('error');
            await loadRecords();
        }
    }

    async function deleteRecord(record) {
        setMessage('');
        setMessageTone('neutral');

        try {
            await request(`/api/attendance-records/${record.id}`, { method: 'DELETE' });
            setMessage('勤怠を削除しました。');
            setMessageTone('success');
            if (isHistoryPage) {
                await loadHistoryRecords(record.user_id, selectedHistoryDepartment, selectedHistoryDepartment ? historyDepartmentPage : 1);
            } else {
                await loadRecords();
            }
        } catch (error) {
            setMessage(error.message);
        }
    }

    function scheduleBusinessReportSave(record, value) {
        if (!record.can_report_edit) return;

        clearTimeout(reportSaveTimers.current[record.id]);
        setBusinessReportDrafts((currentDrafts) => ({ ...currentDrafts, [record.id]: value }));

        reportSaveTimers.current[record.id] = setTimeout(async () => {
            try {
                await request(`/api/attendance-records/${record.id}`, {
                    method: 'PUT',
                    body: JSON.stringify({
                        user_id: Number(record.user_id),
                        work_date: record.work_date,
                        clock_in: record.clock_in,
                        clock_out: record.clock_out,
                        declared_clock_in: record.declared_clock_in,
                        declared_clock_out: record.declared_clock_out,
                        declared_break_minutes: record.declared_break_minutes === '' || record.declared_break_minutes === null ? null : Number(record.declared_break_minutes),
                        work_location: record.work_location || null,
                        meal_percentage: record.work_location === 'home' || record.meal_percentage === '' || record.meal_percentage === null ? null : Number(record.meal_percentage),
                        missed_meal: record.work_location === 'home' ? false : Boolean(record.missed_meal),
                        break_minutes: Number(record.break_minutes),
                        status: record.status,
                        note: value,
                        admin_comment: record.admin_comment || '',
                    }),
                });
                setRecords((currentRecords) => currentRecords.map((currentRecord) => (
                    currentRecord.id === record.id ? { ...currentRecord, note: value } : currentRecord
                )));
                setMessage('業務報告を自動保存しました。');
                setMessageTone('success');
                if (isAdmin) {
                    await loadBusinessReports(record.user_id);
                }
            } catch (error) {
                setMessage(error.message);
                setMessageTone('error');
            }
        }, 5000);
    }

    function editRecord(record) {
        const currentScrollY = window.scrollY;
        const recordUser = usersById.get(String(record.user_id));
        const editableStatuses = ['working', 'completed', 'not_clocked', 'holiday', 'absence', 'paid_leave', 'planned_vacation', 'morning_paid_leave', 'afternoon_paid_leave', 'late', 'early_leave', 'late_and_early_leave', 'business_support'];
        const displayedStatus = ['early_leave_planned', 'early_leave_done'].includes(record.display_status_type)
            ? 'early_leave'
            : record.display_status_type;
        const editableStatus = editableStatuses.includes(displayedStatus)
            ? displayedStatus
            : (editableStatuses.includes(record.status) ? record.status : (record.id ? 'completed' : 'not_clocked'));

        setForm({
            id: record.id,
            user_id: String(record.user_id),
            work_date: record.work_date,
            clock_in: record.clock_in || '',
            clock_out: record.clock_out || '',
            declared_clock_in: record.declared_clock_in || '',
            declared_clock_out: record.declared_clock_out || '',
            declared_break_minutes: record.declared_break_minutes ?? '',
            work_location: record.work_location || '',
            meal_percentage: record.meal_percentage ?? '',
            missed_meal: Boolean(record.missed_meal),
            break_minutes: record.status === 'not_clocked' ? 0 : record.break_minutes,
            status: editableStatus,
            request_reason_category: record.request_reason_category || '私用のため',
            request_reason: record.request_reason || '',
            request_absent_start_time: record.request_absent_start_time || '',
            request_absent_end_time: record.request_absent_end_time || '',
            request_late_start_time: record.request_late_start_time || '',
            request_late_end_time: record.request_late_end_time || '',
            request_early_leave_start_time: record.request_early_leave_start_time || '',
            request_early_leave_end_time: record.request_early_leave_end_time || '',
            note: record.note || '',
            admin_comment: record.admin_comment || '',
            attendance_requests: record.attendance_requests ?? [],
        });
        if (isAdmin) {
            setSelectedUser(String(record.user_id));
            setUserSearch(recordUser?.name ?? record.employee ?? '');
            setRequestForm((current) => ({ ...current, user_id: String(record.user_id) }));
        }
        setMessage('');
        setShowAttendanceRecordForm(true);
        if (!isHistoryPage) {
            setActiveAdminTab('attendance');
        }
        setTimeout(() => window.scrollTo({ top: currentScrollY }), 0);
        setTimeout(() => window.scrollTo({ top: currentScrollY }), 80);
        requestAnimationFrame(() => {
            window.scrollTo({ top: currentScrollY });
            requestAnimationFrame(() => window.scrollTo({ top: currentScrollY }));
        });
    }

    function resetForm() {
        setForm({ ...blankForm, user_id: selectedUser || viewer?.id || '' });
        setErrors({});
        setShowAttendanceRecordForm(false);
    }

    const selectedUserName = useMemo(
        () => users.find((user) => String(user.id) === String(selectedUser))?.name ?? viewer?.name ?? '従業員',
        [users, selectedUser, viewer],
    );
    const selectedTargetUser = useMemo(
        () => users.find((user) => String(user.id) === String(selectedUser)),
        [users, selectedUser],
    );
    const usersById = useMemo(
        () => new Map(users.map((user) => [String(user.id), user])),
        [users],
    );
    const filteredUsers = useMemo(() => {
        const keyword = userSearch.trim().toLowerCase();
        if (!keyword) return users;

        return users.filter((user) => (
            user.name.toLowerCase().includes(keyword)
            || user.email.toLowerCase().includes(keyword)
            || String(user.management_number ?? '').toLowerCase().includes(keyword)
        ));
    }, [users, userSearch]);

    useEffect(() => {
        if (!isAdmin || !selectedUser) return;

        const container = targetUserListRef.current;
        const selectedButton = selectedTargetUserButtonRef.current;
        if (!container || !selectedButton) return;

        requestAnimationFrame(() => {
            const containerRect = container.getBoundingClientRect();
            const buttonRect = selectedButton.getBoundingClientRect();
            const isAbove = buttonRect.top < containerRect.top;
            const isBelow = buttonRect.bottom > containerRect.bottom;

            if (isAbove || isBelow) {
                container.scrollTop += buttonRect.top - containerRect.top - 12;
            }
        });
    }, [isAdmin, selectedUser, filteredUsers.length]);

    const monthOptions = useMemo(() => buildMonthOptions(month), [month]);
    const requestTypeHidesTime = ['absence', 'paid_leave', 'business_support', 'change', 'off_hours_medical'].includes(requestForm.type);
    const requestTypeHidesStartTime = requestTypeHidesTime;
    const requestTypeHidesEndTime = requestTypeHidesTime;
    const attendanceRequestTypeTabs = useMemo(
        () => [['all', 'すべて'], ...Object.entries(requestTypes)],
        [],
    );
    const selectedProfileUser = useMemo(
        () => users.find((user) => String(user.id) === String(selectedUser)),
        [users, selectedUser],
    );
    const selectedProfileDraft = selectedProfileUser
        ? userProfileDrafts[selectedProfileUser.id]
        : null;
    const selectedBusinessCategoryOptions = selectedProfileDraft
        ? businessCategoriesForDepartment(selectedProfileDraft.department)
        : businessCategoriesForDepartment('新今宮');
    const usersByDepartment = useMemo(() => {
        const groupedUsers = new Map();

        users.forEach((user) => {
            const department = user.department || '未設定';
            if (!groupedUsers.has(department)) groupedUsers.set(department, []);
            groupedUsers.get(department).push(user);
        });

        return Array.from(groupedUsers.entries());
    }, [users]);
    const selectedRetiredUser = useMemo(
        () => retiredUsers.find((user) => String(user.id) === String(selectedRetiredUserId)),
        [retiredUsers, selectedRetiredUserId],
    );
    const sortedAttendanceRequests = useMemo(() => {
        const valueForSort = (attendanceRequest, key) => {
            if (key === 'type') return requestTypes[attendanceRequest.type] ?? attendanceRequest.type;
            if (key === 'time') return `${attendanceRequest.start_time || ''}-${attendanceRequest.end_time || ''}`;
            if (key === 'reason_category') return attendanceRequest.reason_category || '';
            if (key === 'admin_checked' || key === 'service_manager_checked') return attendanceRequest[key] ? 1 : 0;
            if (key === 'status') return requestStatusLabels[attendanceRequest.status] ?? attendanceRequest.status;

            return attendanceRequest[key] ?? '';
        };

        return [...attendanceRequests].sort((left, right) => {
            const leftValue = valueForSort(left, attendanceRequestSort.key);
            const rightValue = valueForSort(right, attendanceRequestSort.key);
            const result = typeof leftValue === 'number' && typeof rightValue === 'number'
                ? leftValue - rightValue
                : String(leftValue).localeCompare(String(rightValue), 'ja');

            return attendanceRequestSort.direction === 'asc' ? result : -result;
        });
    }, [attendanceRequests, attendanceRequestSort]);
    const displayedRecords = useMemo(() => (isHistoryPage ? records : paginate(records, recordsPage)), [isHistoryPage, records, recordsPage]);
    const departmentOptions = useMemo(
        () => Array.from(new Set(users.map((user) => user.department || '未設定'))).sort((left, right) => left.localeCompare(right, 'ja')),
        [users],
    );
    const historyDateColumns = useMemo(() => {
        if (historyMode !== 'department') return [];

        return Array.from(new Set(historyRecords.map((record) => record.work_date)))
            .sort((left, right) => String(left).localeCompare(String(right)));
    }, [historyMode, historyRecords]);
    const sortedHistoryRecords = useMemo(
        () => [...historyRecords].sort((left, right) => String(left.work_date).localeCompare(String(right.work_date))),
        [historyRecords],
    );
    const historyRecordsByUser = useMemo(() => {
        if (historyMode !== 'department') return [];

        const groups = new Map();

        historyRecords.forEach((record) => {
            const userKey = String(record.user_id);

            if (!groups.has(userKey)) {
                groups.set(userKey, {
                    user_id: record.user_id,
                    employee: record.employee,
                    recordsByDate: new Map(),
                });
            }

            groups.get(userKey).recordsByDate.set(record.work_date, record);
        });

        return Array.from(groups.values());
    }, [historyMode, historyRecords]);
    const historyColumnCount = 9;
    const visibleAdminMessages = useMemo(
        () => adminMessages.filter((adminMessage) => showCollapsedAdminMessages || !adminMessage.is_collapsed_default),
        [adminMessages, showCollapsedAdminMessages],
    );
    const collapsedAdminMessages = useMemo(
        () => adminMessages.filter((adminMessage) => adminMessage.is_collapsed_default),
        [adminMessages],
    );
    const paginatedTodayBusinessReports = useMemo(() => paginate(todayBusinessReports, todayBusinessReportsPage), [todayBusinessReports, todayBusinessReportsPage]);
    const paginatedMonthlyBusinessReports = useMemo(() => paginate(monthlyBusinessReports, monthlyBusinessReportsPage), [monthlyBusinessReports, monthlyBusinessReportsPage]);

    useEffect(() => {
        setHistoryDepartmentPage((current) => Math.min(current, Math.max(1, Math.ceil(historyDepartmentTotalUsers / historyDepartmentPageSize))));
    }, [historyDepartmentTotalUsers]);

    function updateUserProfileField(field, value) {
        if (!selectedProfileUser) return;

        setUserProfileMessage('');
        setUserProfileDrafts((currentDrafts) => {
            const currentDraft = currentDrafts[selectedProfileUser.id] ?? userProfileDraft(selectedProfileUser);
            const nextDraft = {
                ...currentDraft,
                [field]: value,
                ...(field === 'hire_date' ? { paid_leave_remaining_days: calculateLegalPaidLeaveDays(value) } : {}),
            };

            if (field === 'department') {
                nextDraft.business_category = normalizeBusinessCategory(value, currentDraft.business_category);
            }

            return {
                ...currentDrafts,
                [selectedProfileUser.id]: nextDraft,
            };
        });
    }

    function updateUserProfileWorkday(weekday, field, value) {
        if (!selectedProfileUser) return;

        setUserProfileMessage('');
        setUserProfileDrafts((currentDrafts) => {
            const currentDraft = currentDrafts[selectedProfileUser.id] ?? userProfileDraft(selectedProfileUser);

            return {
                ...currentDrafts,
                [selectedProfileUser.id]: {
                    ...currentDraft,
                    workday_settings: {
                        ...currentDraft.workday_settings,
                        [weekday]: {
                            ...currentDraft.workday_settings[weekday],
                            [field]: value,
                        },
                    },
                },
            };
        });
    }

    function changeAttendanceRequestSort(key) {
        setAttendanceRequestSort((current) => ({
            key,
            direction: current.key === key && current.direction === 'asc' ? 'desc' : 'asc',
        }));
    }

    function SortableAttendanceRequestHeader({ label, sortKey }) {
        const isActive = attendanceRequestSort.key === sortKey;

        return (
            <button
                className="inline-flex items-center gap-1 font-semibold text-slate-500 hover:text-slate-900"
                type="button"
                onClick={() => changeAttendanceRequestSort(sortKey)}
            >
                {label}
                <span className="text-[10px]">{isActive ? (attendanceRequestSort.direction === 'asc' ? '▲' : '▼') : '↕'}</span>
            </button>
        );
    }

    function attendanceRequestRowClass(attendanceRequest) {
        if (attendanceRequest.request_date === today) {
            return 'bg-sky-50 hover:bg-sky-100';
        }

        if (attendanceRequest.request_date < today) {
            return 'bg-slate-100 hover:bg-slate-200';
        }

        return 'hover:bg-slate-50';
    }

    function attendanceRecordRowClass(record) {
        const exceptionalStatuses = [
            'absence',
            'paid_leave',
            'morning_paid_leave',
            'afternoon_paid_leave',
            'late',
            'early_leave',
            'early_leave_planned',
            'early_leave_done',
            'late_and_early_leave',
            'business_support',
        ];
        const statusType = record.display_status_type || record.status;
        const requestTypes = record.attendance_request_types || [];
        const isExceptionalStatus = exceptionalStatuses.includes(statusType);
        const hasRequiredAttendanceRequest = statusType === 'late_and_early_leave'
            ? requestTypes.includes('late') && requestTypes.includes('early_leave')
            : record.has_attendance_request;

        if (record.id && record.has_attendance_request && !isExceptionalStatus) {
            return 'bg-rose-50 hover:bg-rose-100';
        }

        if (!record.is_planned_vacation && !hasRequiredAttendanceRequest && isExceptionalStatus) {
            return 'bg-amber-50 hover:bg-amber-100';
        }

        if (record.is_non_working_day || ['paid_leave', 'planned_vacation', 'morning_paid_leave', 'afternoon_paid_leave'].includes(record.display_status_type)) {
            return 'bg-slate-100 hover:bg-slate-100';
        }

        return 'hover:bg-slate-50';
    }

    function updateDisplayDate(value) {
        setDisplayDate(value || today);
    }

    function defaultDeclaredWorkTimes() {
        const targetUserId = selectedUser || viewer?.id;
        const user = users.find((currentUser) => String(currentUser.id) === String(targetUserId)) ?? viewer;
        const weekday = String(new Date(`${today}T00:00:00+09:00`).getDay() || 7);
        const setting = user?.workday_settings?.[weekday];

        return {
            declared_clock_in: setting?.default_clock_in ?? user?.default_clock_in ?? '09:00',
            declared_clock_out: setting?.default_clock_out ?? user?.default_clock_out ?? '18:00',
            declared_break_minutes: setting?.default_break_minutes ?? user?.default_break_minutes ?? 60,
            work_location: 'office',
            meal_percentage: '',
            missed_meal: false,
        };
    }

    function defaultWorkTimeLabel(user, workDate = displayDate) {
        if (!user) return '';

        const weekday = String(new Date(`${workDate || today}T00:00:00+09:00`).getDay() || 7);
        const setting = user.workday_settings?.[weekday];

        if (setting && !setting.is_working_day) {
            return '勤務なし';
        }

        const clockIn = setting?.default_clock_in ?? user.default_clock_in ?? '09:00';
        const clockOut = setting?.default_clock_out ?? user.default_clock_out ?? '18:00';
        const breakMinutes = setting?.default_break_minutes ?? user.default_break_minutes ?? 60;

        return `${clockIn}〜${clockOut} / 休憩${breakMinutes}分`;
    }

    function mealDisplayLabel(record) {
        if (!record || record.work_location === 'home') return '無';
        if (record.missed_meal) return '欠食';
        if (record.meal_percentage === null || record.meal_percentage === undefined || record.meal_percentage === '') return '-';

        return `${record.meal_percentage}%`;
    }

    function attendanceReasonLabel(record) {
        if (!record) return '-';

        return record.display_status ?? statusLabels[record.display_status_type] ?? statusLabels[record.status] ?? record.status ?? '-';
    }

    function openClockOutModal() {
        if (!clockStatus.can_clock_out) {
            setMessage(clockStatus.clock_out_disabled_reason || '退勤できません。');
            setMessageTone('error');
            return;
        }

        setClockOutDraft(defaultDeclaredWorkTimes());
        setShowClockOutModal(true);
    }

    function canPressClockOutButton() {
        return clockStatus.can_clock_out || clockStatus.clock_out_disabled_reason === '業務報告を入力してから退勤してください。';
    }

    function selectTargetUser(user) {
        const userId = String(user.id);
        setSelectedUser(userId);
        setUserSearch(user.name);
        setForm((current) => ({ ...current, user_id: current.id ? current.user_id : userId }));
        setRequestForm((current) => ({ ...current, user_id: userId }));

        if (isHistoryPage) {
            window.history.replaceState(null, '', `/attendance-history?user_id=${userId}&month=${month}`);
        }

        if (isBusinessReportHistoryPage) {
            window.history.replaceState(null, '', `/business-report-history?user_id=${userId}&month=${month}`);
        }
    }

    function syncDepartmentHistoryScroll(source, target) {
        if (!source || !target || isSyncingDepartmentHistoryScroll.current) return;

        isSyncingDepartmentHistoryScroll.current = true;
        target.scrollLeft = source.scrollLeft;
        requestAnimationFrame(() => {
            isSyncingDepartmentHistoryScroll.current = false;
        });
    }

    if (!hasCompletedInitialLoad || !isViewerResolved) {
        return (
            <main className="flex min-h-screen items-center justify-center bg-[#f3f8fc] px-4 text-slate-950">
                <section className="w-full max-w-sm rounded-lg border border-slate-200 bg-white p-6 text-center shadow-sm">
                    <p className="text-sm font-medium text-sky-700">Attendance Manager</p>
                    {initialLoadError ? (
                        <>
                            <p className="mt-3 text-sm font-semibold text-rose-700">読み込みに失敗しました。</p>
                            <p className="mt-2 text-xs text-slate-500">{initialLoadError}</p>
                            <button className="primary-button mx-auto mt-4" type="button" onClick={loadRecords}>
                                再読み込み
                            </button>
                        </>
                    ) : (
                        <p className="mt-3 text-sm font-semibold text-slate-600">読み込み中...</p>
                    )}
                </section>
            </main>
        );
    }

    return (
        <main className="min-h-screen bg-[#f3f8fc] text-slate-950">
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
                <header className="flex flex-col gap-4 border-b border-slate-200 pb-5 lg:flex-row lg:items-end lg:justify-between">
                    <a className="w-fit rounded-md focus:outline-none focus:ring-2 focus:ring-sky-300" href="/">
                        <p className="text-sm font-medium text-sky-700">Attendance Manager</p>
                        <h1 className="mt-1 text-3xl font-semibold text-slate-950 hover:text-sky-700">勤怠管理</h1>
                    </a>
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                        <div className="rounded-md border border-slate-200 bg-white px-4 py-2 shadow-sm">
                            <p className="text-xs font-semibold text-slate-500">ログイン中</p>
                            <p className="text-sm font-semibold text-slate-950">{isAdmin ? '管理者' : viewer?.name}</p>
                        </div>
                        <div className="field-label min-w-72">
                            対象月
                            <div className="flex items-center gap-2">
                                <button className="icon-button" type="button" onClick={() => setMonth(shiftMonth(month, -1))} title="前月">
                                    <ChevronLeft size={17} />
                                </button>
                                <select className="field-control min-w-36" value={month} onChange={(event) => setMonth(event.target.value)}>
                                    {monthOptions.map((monthOption) => (
                                        <option key={monthOption} value={monthOption}>
                                            {formatMonthLabel(monthOption)}
                                        </option>
                                    ))}
                                </select>
                                <button className="icon-button" type="button" onClick={() => setMonth(shiftMonth(month, 1))} title="翌月">
                                    <ChevronRight size={17} />
                                </button>
                                <button className="secondary-button px-3" type="button" onClick={() => setMonth(currentMonth)}>
                                    今月
                                </button>
                            </div>
                        </div>
                        <form method="POST" action="/logout">
                            <input type="hidden" name="_token" value={csrfToken} />
                            <button className="secondary-button" type="submit" title="ログアウト">
                                <LogOut size={17} />
                                ログアウト
                            </button>
                        </form>
                    </div>
                </header>

                {isAdmin && (
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="grid gap-4 lg:grid-cols-[minmax(280px,420px)_1fr] lg:items-start">
                            <label className="field-label">
                                対象ユーザー検索
                                <span className="relative block">
                                    <input
                                        className="field-control pr-10"
                                        placeholder="ユーザー名・メール・管理番号で検索"
                                        value={userSearch}
                                        onChange={(event) => setUserSearch(event.target.value)}
                                    />
                                    {userSearch !== '' && (
                                        <button
                                            aria-label="検索文字列をクリア"
                                            className="absolute right-2 top-1/2 inline-flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                                            type="button"
                                            onClick={() => setUserSearch('')}
                                        >
                                            <X size={16} />
                                        </button>
                                    )}
                                </span>
                            </label>
                            <div className="grid gap-2">
                                <p className="flex items-center text-xs font-semibold text-slate-500">
                                    <span>選択中: {selectedUserName}</span>
                                    <RetirementScheduledBadge user={selectedTargetUser} />
                                </p>
                                <div ref={targetUserListRef} className="flex max-h-36 flex-wrap gap-2 overflow-y-auto">
                                    {filteredUsers.length === 0 ? (
                                        <span className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">該当ユーザーがいません。</span>
                                    ) : filteredUsers.map((user) => (
                                        <button
                                            key={user.id}
                                            ref={String(user.id) === String(selectedUser) ? selectedTargetUserButtonRef : null}
                                            className={String(user.id) === String(selectedUser) ? 'primary-button px-3 py-2 text-sm' : 'secondary-button px-3 py-2 text-sm'}
                                            type="button"
                                            onClick={() => selectTargetUser(user)}
                                        >
                                            <span>{user.name}</span>
                                            <RetirementScheduledBadge user={user} />
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </section>
                )}

                {isAdmin && (!isStandaloneAdminPage || isHistoryPage) && (
                    <nav className="sticky top-0 z-20 -mx-4 border-y border-slate-200 bg-[#f3f8fc]/95 px-4 py-3 backdrop-blur sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8">
                        <div className="flex gap-2 overflow-x-auto">
                            {visibleAdminTabs.map(([tabKey, label]) => (
                                <button
                                    key={tabKey}
                                    className={activeAdminTab === tabKey ? 'primary-button whitespace-nowrap px-3 py-2 text-sm' : 'secondary-button whitespace-nowrap px-3 py-2 text-sm'}
                                    type="button"
                                    onClick={() => {
                                        if (isHistoryPage && tabKey !== 'attendance') {
                                            window.location.href = `/?user_id=${encodeURIComponent(selectedUser || '')}&month=${encodeURIComponent(month)}&active_tab=${encodeURIComponent(tabKey)}`;

                                            return;
                                        }

                                        setActiveAdminTab(tabKey);
                                    }}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </nav>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'messages' && (
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <form className="grid gap-3" onSubmit={submitAdminMessage}>
                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h2 className="text-base font-semibold">お知らせメッセージ</h2>
                                    <p className="mt-1 text-sm text-slate-500">全員へ一斉送信されます。</p>
                                </div>
                                <button className="primary-button" type="submit" disabled={!adminMessageDraft.trim()}>
                                    <Send size={17} />
                                    全員に送信
                                </button>
                            </div>
                            <textarea
                                className="field-control min-h-24 resize-y"
                                placeholder="全員へ表示するお知らせを入力"
                                value={adminMessageDraft}
                                onChange={(event) => setAdminMessageDraft(event.target.value)}
                            />
                            <div className="grid gap-2">
                                {adminMessages.length === 0 ? (
                                    <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">全員宛のお知らせはまだありません。</p>
                                ) : (
                                    <>
                                        {collapsedAdminMessages.length > 0 && (
                                            <button
                                                className="secondary-button w-fit text-sm"
                                                type="button"
                                                onClick={() => setShowCollapsedAdminMessages((current) => !current)}
                                            >
                                                {showCollapsedAdminMessages ? '過去のお知らせを非表示' : `過去のお知らせを表示（${collapsedAdminMessages.length}件）`}
                                            </button>
                                        )}
                                        {visibleAdminMessages.map((adminMessage) => (
                                            <div key={adminMessage.id} className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                                                <div className="mb-1 flex flex-wrap items-center gap-2 text-xs font-semibold text-slate-500">
                                                    <span>{adminMessage.sent_at}</span>
                                                    <span>{adminMessage.admin}</span>
                                                </div>
                                                <p className="whitespace-pre-wrap text-slate-700">{adminMessage.body}</p>
                                            </div>
                                        ))}
                                        {visibleAdminMessages.length === 0 && (
                                            <p className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">3日以内のお知らせはありません。</p>
                                        )}
                                    </>
                                )}
                            </div>
                        </form>
                    </section>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'admins' && (
                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-2 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">管理者一覧</h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    強管理者だけがユーザー情報にアクセスできます。
                                </p>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="table-cell">名前</th>
                                        <th className="table-cell">メールアドレス</th>
                                        <th className="table-cell">種類</th>
                                        <th className="table-cell">登録日時</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {isAdminUsersLoading ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={4}>読み込み中...</td></tr>
                                    ) : adminUsers.length === 0 ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={4}>管理者はいません。</td></tr>
                                    ) : adminUsers.map((adminUser) => (
                                        <tr key={adminUser.id} className="hover:bg-slate-50">
                                            <td className="table-cell font-medium text-slate-900">{adminUser.name}</td>
                                            <td className="table-cell text-slate-600">{adminUser.email}</td>
                                            <td className="table-cell">
                                                {isStrongAdmin ? (
                                                    <select
                                                        className="field-control min-w-36"
                                                        value={adminUser.admin_level}
                                                        onChange={(event) => updateAdminLevel(adminUser, event.target.value)}
                                                        disabled={Number(adminUser.id) === Number(viewer?.id)}
                                                        title={Number(adminUser.id) === Number(viewer?.id) ? '自分自身の権限は変更できません' : '管理者種類'}
                                                    >
                                                        <option value="strong">強管理者</option>
                                                        <option value="weak">弱管理者</option>
                                                    </select>
                                                ) : (
                                                    <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700 ring-1 ring-slate-200">
                                                        {adminUser.admin_level_label}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="table-cell text-slate-600">{adminUser.created_at || '-'}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'calendar' && (
                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-2 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">カレンダー管理</h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    休日、土曜出勤日、計画有給、自由出勤日などを勤怠判定へ反映します。
                                </p>
                            </div>
                            <div className="field-label md:w-48">
                                対象月
                                <select className="field-control" value={month} onChange={(event) => setMonth(event.target.value)}>
                                    {monthOptions.map((monthOption) => (
                                        <option key={monthOption} value={monthOption}>
                                            {formatMonthLabel(monthOption)}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        </div>
                        <div className="grid gap-4 p-5">
                            <form className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 md:grid-cols-[160px_220px_1fr_auto] md:items-end" onSubmit={submitCalendarEntry}>
                                <label className="field-label">
                                    日付
                                    <input
                                        className="field-control"
                                        type="date"
                                        value={calendarEntryForm.date}
                                        onChange={(event) => setCalendarEntryForm({ ...calendarEntryForm, date: event.target.value })}
                                    />
                                </label>
                                <label className="field-label">
                                    種類
                                    <select
                                        className="field-control"
                                        value={calendarEntryForm.type}
                                        onChange={(event) => setCalendarEntryForm({ ...calendarEntryForm, type: event.target.value })}
                                    >
                                        {Object.entries(calendarEntryTypes).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="field-label">
                                    説明
                                    <input
                                        className="field-control"
                                        value={calendarEntryForm.description}
                                        onChange={(event) => setCalendarEntryForm({ ...calendarEntryForm, description: event.target.value })}
                                    />
                                </label>
                                <button className="primary-button" type="submit">
                                    <Save size={18} />
                                    追加
                                </button>
                            </form>
                            <div className="flex flex-wrap gap-2">
                                {Object.entries(calendarCounts).length === 0 ? (
                                    <span className="rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-500">インポート済み件数はありません。</span>
                                ) : Object.entries(calendarCounts).map(([type, count]) => (
                                    <span key={type} className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                        {count.label}: {count.total}件
                                    </span>
                                ))}
                            </div>
                            <div className="overflow-x-auto rounded-lg border border-slate-200">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                        <tr>
                                            <th className="table-cell">日付</th>
                                            <th className="table-cell">種類</th>
                                            <th className="table-cell">説明</th>
                                            <th className="table-cell text-right">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {isCalendarEntriesLoading ? (
                                            <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={4}>読み込み中...</td></tr>
                                        ) : calendarEntries.length === 0 ? (
                                            <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={4}>この月のカレンダー登録はありません。</td></tr>
                                        ) : calendarEntries.map((entry) => (
                                            <tr key={entry.id} className="hover:bg-slate-50">
                                                <td className="table-cell font-medium">{entry.date}</td>
                                                <td className="table-cell">{entry.type_label}</td>
                                                <td className="table-cell max-w-96 truncate text-slate-600">{entry.description || '-'}</td>
                                                <td className="table-cell text-right">
                                                    <button className="secondary-button text-sm text-rose-700" type="button" onClick={() => deleteCalendarEntry(entry)}>
                                                        <Trash2 size={16} />
                                                        削除
                                                    </button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'selfReports' && (
                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-2 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">自己管理レポート</h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    カレンダー管理の自己管理レポート提出日ごとに、全員の提出状況を確認できます。
                                </p>
                            </div>
                            <div className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                対象日: {selfManagementReportActiveDate || '-'}
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-[1400px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="table-cell">名前</th>
                                        <th className="table-cell">提出</th>
                                        <th className="table-cell">仕事評価</th>
                                        <th className="table-cell">生活評価</th>
                                        <th className="table-cell">当月の振り返り</th>
                                        <th className="table-cell">来月の目標</th>
                                        <th className="table-cell">スキルアップ状況</th>
                                        <th className="table-cell">行動状況</th>
                                        <th className="table-cell">行動詳細</th>
                                        <th className="table-cell">その他</th>
                                        <th className="table-cell">管理者コメント</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {isSelfManagementReportsLoading ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={11}>読み込み中...</td></tr>
                                    ) : selfManagementReports.length === 0 ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={11}>自己管理レポートはありません。</td></tr>
                                    ) : selfManagementReports.map((report) => (
                                        <tr key={`self-report-${report.user_id}`} className={report.submitted ? 'hover:bg-slate-50' : 'bg-amber-50/60 hover:bg-amber-50'}>
                                            <td className="table-cell font-medium">
                                                <div className="grid gap-1">
                                                    {report.management_number && <span className="text-xs font-normal text-slate-400">{report.management_number}</span>}
                                                    <span>{report.employee}</span>
                                                    {report.department && <span className="text-xs font-normal text-slate-500">{report.department}</span>}
                                                </div>
                                            </td>
                                            <td className="table-cell">
                                                <span className={report.submitted ? 'inline-flex rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700 ring-1 ring-sky-200' : 'inline-flex rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-amber-200'}>
                                                    {report.submitted ? '提出済' : '未提出'}
                                                </span>
                                                {report.submitted_at && <div className="mt-1 text-xs text-slate-500">{report.submitted_at}</div>}
                                            </td>
                                            <td className="table-cell max-w-48 whitespace-pre-wrap">{report.work_rating || '-'}</td>
                                            <td className="table-cell max-w-48 whitespace-pre-wrap">{report.life_rating || '-'}</td>
                                            <td className="table-cell max-w-72 whitespace-pre-wrap">{report.monthly_reflection || '-'}</td>
                                            <td className="table-cell max-w-72 whitespace-pre-wrap">{report.next_month_goal || '-'}</td>
                                            <td className="table-cell max-w-72 whitespace-pre-wrap">{report.skill_progress || '-'}</td>
                                            <td className="table-cell max-w-48 whitespace-pre-wrap">{report.activity_status || '-'}</td>
                                            <td className="table-cell max-w-72 whitespace-pre-wrap">{report.activity_detail || '-'}</td>
                                            <td className="table-cell max-w-72 whitespace-pre-wrap">{report.other || '-'}</td>
                                            <td className="table-cell min-w-72">
                                                {report.id ? (
                                                    <div className="grid gap-2">
                                                        <textarea
                                                            className="field-control min-h-20 resize-y"
                                                            value={selfManagementAdminCommentDrafts[report.id] ?? ''}
                                                            onChange={(event) => setSelfManagementAdminCommentDrafts((current) => ({
                                                                ...current,
                                                                [report.id]: event.target.value,
                                                            }))}
                                                        />
                                                        <button className="secondary-button w-fit text-sm" type="button" onClick={() => saveSelfManagementAdminComment(report)}>
                                                            <Save size={16} />
                                                            保存
                                                        </button>
                                                    </div>
                                                ) : (
                                                    <span className="text-slate-400">未提出のため入力できません</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </section>
                )}

                {!isAdmin && (
                    <section className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                            <h2 className="text-lg font-semibold">今月の土曜出勤・祝日休み</h2>
                            <span className="text-sm font-semibold text-slate-500">{formatMonthLabel(month)}</span>
                        </div>
                        <div className="mt-4 grid gap-3 md:grid-cols-2">
                            <div className="rounded-md border border-sky-100 bg-sky-50 p-3">
                                <p className="text-sm font-semibold text-sky-800">土曜出勤</p>
                                {(calendarHighlights.saturday_work ?? []).length === 0 ? (
                                    <p className="mt-2 text-sm text-slate-500">今月の土曜出勤はありません。</p>
                                ) : (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {calendarHighlights.saturday_work.map((entry) => (
                                            <span key={`saturday-work-${entry.id}`} className="rounded-full bg-white px-3 py-1 text-sm font-semibold text-sky-800 ring-1 ring-sky-100">
                                                {formatDateWithWeekday(entry.date)}
                                                {entry.description ? ` ${entry.description}` : ''}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                            <div className="rounded-md border border-rose-100 bg-rose-50 p-3">
                                <p className="text-sm font-semibold text-rose-800">祝日休み</p>
                                {(calendarHighlights.holiday_off ?? []).length === 0 ? (
                                    <p className="mt-2 text-sm text-slate-500">今月の祝日休みはありません。</p>
                                ) : (
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        {calendarHighlights.holiday_off.map((entry) => (
                                            <span key={`holiday-off-${entry.id}`} className="rounded-full bg-white px-3 py-1 text-sm font-semibold text-rose-800 ring-1 ring-rose-100">
                                                {formatDateWithWeekday(entry.date)}
                                                {entry.description ? ` ${entry.description}` : ''}
                                            </span>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {!isAdmin && isSelfManagementReportActive && selfManagementReportActiveDate && (
                    <section className="rounded-lg border border-sky-200 bg-sky-50 p-5 text-sky-950 shadow-sm">
                        {selfManagementReportForm.submitted ? (
                            <p className="text-sm font-semibold">自己管理レポートを提出しました。</p>
                        ) : (
                        <form className="grid gap-4" onSubmit={submitSelfManagementReport}>
                            <div className="flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <h2 className="text-lg font-semibold">自己管理レポート</h2>
                                    <p className="mt-1 text-sm text-sky-800">対象日: {selfManagementReportActiveDate}</p>
                                </div>
                                <button className="primary-button" type="submit" disabled={isSelfManagementReportsLoading}>
                                    <Send size={17} />
                                    送信する
                                </button>
                            </div>
                            <input type="hidden" value={selfManagementReportForm.report_date} readOnly />
                            <div className="grid gap-3 md:grid-cols-2">
                                {selfManagementReportFields.map(([field, label, kind]) => (
                                    <div key={field} className={kind === 'textarea' || kind === 'radio' || kind === 'yesNoRadio' ? 'field-label md:col-span-2' : 'field-label'}>
                                        <span>{label}</span>
                                        {kind === 'textarea' ? (
                                            <textarea
                                                className="field-control min-h-24 resize-y bg-white"
                                                value={selfManagementReportForm[field] ?? ''}
                                                onChange={(event) => setSelfManagementReportForm((current) => ({
                                                    ...current,
                                                    [field]: event.target.value,
                                                }))}
                                            />
                                        ) : kind === 'radio' ? (
                                            <div className="grid gap-2 rounded-md border border-sky-200 bg-white p-3 sm:grid-cols-2">
                                                {selfManagementRatingOptions.map((option) => (
                                                    <label key={`${field}-${option}`} className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-sky-950 hover:bg-sky-50">
                                                        <input
                                                            className="size-4 accent-sky-600"
                                                            type="radio"
                                                            name={field}
                                                            value={option}
                                                            checked={(selfManagementReportForm[field] ?? '') === option}
                                                            onChange={(event) => setSelfManagementReportForm((current) => ({
                                                                ...current,
                                                                [field]: event.target.value,
                                                            }))}
                                                        />
                                                        <span>{option}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        ) : kind === 'yesNoRadio' ? (
                                            <div className="flex flex-wrap gap-3 rounded-md border border-sky-200 bg-white p-3">
                                                {yesNoOptions.map((option) => (
                                                    <label key={`${field}-${option}`} className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-sky-950 hover:bg-sky-50">
                                                        <input
                                                            className="size-4 accent-sky-600"
                                                            type="radio"
                                                            name={field}
                                                            value={option}
                                                            checked={(selfManagementReportForm[field] ?? '') === option}
                                                            onChange={(event) => setSelfManagementReportForm((current) => ({
                                                                ...current,
                                                                [field]: event.target.value,
                                                            }))}
                                                        />
                                                        <span>{option}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        ) : (
                                            <input
                                                className="field-control bg-white"
                                                value={selfManagementReportForm[field] ?? ''}
                                                onChange={(event) => setSelfManagementReportForm((current) => ({
                                                    ...current,
                                                    [field]: event.target.value,
                                                }))}
                                            />
                                        )}
                                        <ErrorText errors={errors[field]} />
                                    </div>
                                ))}
                                {selfManagementReportForm.admin_comment && (
                                    <div className="md:col-span-2 rounded-md border border-sky-200 bg-white/80 px-3 py-2">
                                        <p className="text-xs font-semibold text-sky-700">管理者コメント</p>
                                        <p className="mt-1 whitespace-pre-wrap text-sm text-sky-950">{selfManagementReportForm.admin_comment}</p>
                                    </div>
                                )}
                            </div>
                        </form>
                        )}
                    </section>
                )}

                {!isAdmin && adminMessages.length > 0 && (
                    <section className="rounded-lg border border-amber-200 bg-amber-50 p-5 text-amber-950 shadow-sm">
                        <div className="mb-3 flex items-center gap-2">
                            <FileText size={20} />
                            <h2 className="text-lg font-semibold">管理者からのお知らせ</h2>
                        </div>
                        <div className="grid gap-3">
                            {collapsedAdminMessages.length > 0 && (
                                <button
                                    className="secondary-button w-fit border-amber-300 bg-white text-sm text-amber-800 hover:bg-amber-100"
                                    type="button"
                                    onClick={() => setShowCollapsedAdminMessages((current) => !current)}
                                >
                                    {showCollapsedAdminMessages ? '過去のお知らせを非表示' : `過去のお知らせを表示（${collapsedAdminMessages.length}件）`}
                                </button>
                            )}
                            {visibleAdminMessages.length === 0 && (
                                <p className="rounded-md border border-amber-200 bg-white/70 px-3 py-2 text-sm text-amber-800">3日以内のお知らせはありません。</p>
                            )}
                            {visibleAdminMessages.map((adminMessage) => (
                                <article key={adminMessage.id} className="rounded-md border border-amber-200 bg-white/70 px-3 py-2">
                                    <div className="mb-1 text-xs font-semibold text-amber-700">{adminMessage.sent_at} / {adminMessage.admin}</div>
                                    <p className="whitespace-pre-wrap text-sm">{adminMessage.body}</p>
                                </article>
                            ))}
                        </div>
                    </section>
                )}

                {!isAdmin && (
                    <section className="grid gap-4 md:grid-cols-4">
                        <Metric icon={CalendarDays} label="勤務日数" value={`${summary?.days ?? 0}日`} />
                        <Metric icon={Clock} label="実働時間" value={minutesToHours(summary?.worked_minutes ?? 0)} />
                        <Metric icon={Coffee} label="残業時間" value={minutesToHours(summary?.overtime_minutes ?? 0)} />
                        <Metric icon={UserRound} label="対象者" value={selectedUserName} />
                    </section>
                )}

                {message && (
                    <div className={messageTone === 'success'
                        ? 'rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-sm font-semibold text-sky-800 shadow-sm'
                        : messageTone === 'error'
                            ? 'rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800 shadow-sm'
                            : 'rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-700 shadow-sm'}
                    >
                        {message}
                    </div>
                )}

                {!isStandaloneAdminPage && missingClockOutRecords.length > 0 && (
                    <section className="rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-rose-900 shadow-sm">
                        <p className="text-sm font-bold">退勤ボタンの押し忘れがあります。</p>
                        <p className="mt-1 text-sm">
                            {missingClockOutRecords.map((record) => `${record.employee ? `${record.employee} ` : ''}${record.work_date}（出勤 ${record.clock_in || '-'}）`).join('、')}
                        </p>
                    </section>
                )}

                {isHistoryPage && isAdmin && (
                    <section className="order-20 rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-2 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">{historyMode === 'department' ? selectedHistoryDepartment : selectedUserName} / {historyRange?.month ?? month} の勤怠一覧</h2>
                                <p className="mt-1 text-sm text-slate-500">
                                    {historyRange ? `${historyRange.start} 〜 ${historyRange.end}` : '読み込み中...'}
                                </p>
                            </div>
                            <div className="flex flex-col gap-3 md:flex-row md:items-end">
                                <label className="field-label md:w-52">
                                    部署
                                    <select
                                        className="field-control"
                                        value={selectedHistoryDepartment}
                                        onChange={(event) => {
                                            setHistoryDepartmentPage(1);
                                            setSelectedHistoryDepartment(event.target.value);
                                        }}
                                    >
                                        <option value="">個別表示</option>
                                        {departmentOptions.map((department) => (
                                            <option key={department} value={department}>{department}</option>
                                        ))}
                                    </select>
                                </label>
                                {historyMode !== 'department' && selectedUser && (
                                    <>
                                        <a
                                            className="secondary-button"
                                            href={`/api/attendance-records/history/pdf?user_id=${encodeURIComponent(selectedUser)}&month=${encodeURIComponent(month)}`}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            <Download size={17} />
                                            出勤簿PDF
                                        </a>
                                        <a
                                            className="secondary-button"
                                            href={`/api/attendance-records/history/company-pdf?user_id=${encodeURIComponent(selectedUser)}&month=${encodeURIComponent(month)}`}
                                        >
                                            <Download size={17} />
                                            会社保管用PDF
                                        </a>
                                    </>
                                )}
                                <a
                                    className="secondary-button"
                                    href={selectedUser ? `/?user_id=${encodeURIComponent(selectedUser)}&month=${encodeURIComponent(month)}` : '/'}
                                >
                                    本日の一覧へ戻る
                                </a>
                            </div>
                        </div>
                        {historyMode === 'department' ? (
                            <>
                                <div
                                    ref={departmentHistoryScrollRef}
                                    className="overflow-x-auto"
                                    onScroll={(event) => syncDepartmentHistoryScroll(event.currentTarget, departmentHistoryBottomScrollRef.current)}
                                >
                                    {isHistoryLoading ? (
                                        <p className="px-5 py-10 text-center text-sm text-slate-500">読み込み中...</p>
                                    ) : historyDateColumns.length === 0 ? (
                                        <p className="px-5 py-10 text-center text-sm text-slate-500">対象月の日付がありません。</p>
                                    ) : (
                                        <table className="min-w-max text-left text-sm">
                                        <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                            <tr>
                                                <th className="sticky left-0 z-20 w-44 min-w-44 border-r border-slate-200 bg-slate-50 px-5 py-3 align-bottom">従業員</th>
                                                {historyDateColumns.map((date) => (
                                                    <th key={date} className="w-40 min-w-40 border-r border-slate-200 px-2 py-2 text-center text-slate-700">
                                                        {formatDateWithWeekday(date)}
                                                    </th>
                                                ))}
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {historyRecordsByUser.map((userGroup) => (
                                                <tr key={userGroup.user_id}>
                                                    <td className="sticky left-0 z-10 border-r border-slate-200 bg-white px-5 py-4 font-medium text-slate-800">
                                                        {userGroup.employee}
                                                    </td>
                                                    {historyDateColumns.map((date) => {
                                                        const record = userGroup.recordsByDate.get(date);
                                                        const cellClassName = record ? `px-2 py-2 align-top ${attendanceRecordRowClass(record)}` : 'px-2 py-2 align-top';

                                                        return (
                                                            <td key={`${userGroup.user_id}-${date}`} className={`${cellClassName} w-40 min-w-40 border-r border-slate-200`}>
                                                                {record ? (
                                                                    <div className="grid gap-1.5">
                                                                        <div className="grid grid-cols-[3.5rem_1fr] gap-x-1.5 gap-y-0.5 text-[11px] leading-tight">
                                                                            <span className="font-semibold text-slate-400">出勤</span>
                                                                            <span>{record.clock_in || '-'}</span>
                                                                            <span className="font-semibold text-slate-400">退勤</span>
                                                                            <span>{record.clock_out || '-'}</span>
                                                                            <span className="font-semibold text-slate-400">休憩</span>
                                                                            <span>{record.id || record.status === 'holiday' ? `${record.break_minutes}分` : '-'}</span>
                                                                            <span className="font-semibold text-slate-400">場所</span>
                                                                            <span>{record.work_location_label || '-'}</span>
                                                                            <span className="font-semibold text-slate-400">食事割合</span>
                                                                            <span>{mealDisplayLabel(record)}</span>
                                                                            <span className="font-semibold text-slate-400">事由</span>
                                                                            <span>{attendanceReasonLabel(record)}</span>
                                                                            <span className="font-semibold text-slate-400">追記</span>
                                                                            <span className="line-clamp-2">{record.request_reason || '-'}</span>
                                                                        </div>
                                                                        <div className="flex justify-end gap-2">
                                                                            <button
                                                                                className="icon-button disabled:cursor-not-allowed disabled:opacity-40"
                                                                                type="button"
                                                                                onClick={() => editRecord(record)}
                                                                                title={record.can_edit ? '修正' : '修正できません'}
                                                                                disabled={!record.can_edit}
                                                                            >
                                                                                <Pencil size={16} />
                                                                            </button>
                                                                            <button
                                                                                className="icon-button danger disabled:cursor-not-allowed disabled:opacity-40"
                                                                                type="button"
                                                                                onClick={() => deleteRecord(record)}
                                                                                title={record.can_edit ? '削除' : '削除できません'}
                                                                                disabled={!record.id || !record.can_edit}
                                                                            >
                                                                                <Trash2 size={16} />
                                                                            </button>
                                                                        </div>
                                                                    </div>
                                                                ) : (
                                                                    <span className="text-slate-300">-</span>
                                                                )}
                                                            </td>
                                                        );
                                                    })}
                                                </tr>
                                            ))}
                                        </tbody>
                                        </table>
                                    )}
                                </div>
                                <Pagination
                                    total={historyDepartmentTotalUsers}
                                    page={historyDepartmentPage}
                                    onPageChange={setHistoryDepartmentPage}
                                    perPage={historyDepartmentPageSize}
                                />
                                <div className="h-6" />
                            </>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="min-w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                        <tr>
                                            <th className="table-cell">日付</th>
                                            <th className="table-cell">出勤</th>
                                            <th className="table-cell">退勤</th>
                                            <th className="table-cell">休憩</th>
                                            <th className="table-cell">勤務場所</th>
                                            <th className="table-cell">状態</th>
                                            <th className="table-cell">業務報告</th>
                                            <th className="table-cell">管理者コメント</th>
                                            <th className="table-cell text-right">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-slate-100">
                                        {isHistoryLoading ? (
                                            <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={historyColumnCount}>読み込み中...</td></tr>
                                        ) : historyRecords.length === 0 ? (
                                            <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={historyColumnCount}>対象月の日付がありません。</td></tr>
                                        ) : sortedHistoryRecords.map((record) => (
                                            <tr
                                                key={record.id ?? `history-empty-${record.user_id}-${record.work_date}`}
                                                className={attendanceRecordRowClass(record)}
                                            >
                                                <td className="table-cell font-medium">{formatDateWithWeekday(record.work_date)}</td>
                                                <td className="table-cell">{record.clock_in || ''}</td>
                                                <td className="table-cell">{record.clock_out || ''}</td>
                                                <td className="table-cell">{record.id || record.status === 'holiday' ? `${record.break_minutes}分` : ''}</td>
                                                <td className="table-cell">{record.work_location_label || ''}</td>
                                                <td className="table-cell">
                                                    {(record.display_status || record.status) && (
                                                        <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${statusClasses[record.display_status_type] ?? statusClasses[record.status]}`}>
                                                            {record.display_status ?? statusLabels[record.status]}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="table-cell max-w-72 truncate text-slate-600">{record.note || ''}</td>
                                                <td className="table-cell max-w-80 whitespace-pre-wrap text-slate-600">{record.admin_comment || ''}</td>
                                                <td className="table-cell">
                                                    <div className="flex justify-end gap-2">
                                                        <button
                                                            className="icon-button disabled:cursor-not-allowed disabled:opacity-40"
                                                            type="button"
                                                            onClick={() => editRecord(record)}
                                                            title={record.can_edit ? '修正' : '修正できません'}
                                                            disabled={!record.can_edit}
                                                        >
                                                            <Pencil size={16} />
                                                        </button>
                                                        <button
                                                            className="icon-button danger disabled:cursor-not-allowed disabled:opacity-40"
                                                            type="button"
                                                            onClick={() => deleteRecord(record)}
                                                            title={record.can_edit ? '削除' : '削除できません'}
                                                            disabled={!record.id || !record.can_edit}
                                                        >
                                                            <Trash2 size={16} />
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </section>
                )}

                {showDepartmentHistoryScrollbar && (
                    <div className="fixed bottom-0 left-0 right-0 z-40 border-t border-slate-300 bg-white/95 px-2 py-1 shadow-[0_-4px_14px_rgba(15,23,42,0.12)] backdrop-blur">
                        <div
                            ref={departmentHistoryBottomScrollRef}
                            className="h-5 overflow-x-auto overflow-y-hidden"
                            onScroll={(event) => syncDepartmentHistoryScroll(event.currentTarget, departmentHistoryScrollRef.current)}
                        >
                            <div style={{ width: `${departmentHistoryScrollWidth}px`, height: 1 }} />
                        </div>
                    </div>
                )}

                {isAdmin && isStrongAdmin && !isStandaloneAdminPage && activeAdminTab === 'profile' && (
                    <section className="order-30 rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex items-center gap-2 border-b border-slate-200 p-5">
                            <Settings className="text-sky-700" size={20} />
                            <h2 className="text-lg font-semibold">ユーザー情報編集</h2>
                        </div>
                        <div className="grid gap-4 p-5">
                            {selectedProfileDraft && (
                                <>
                                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                                    <label className="field-label">
                                        氏名
                                        <input className="field-control" value={selectedProfileDraft.name} onChange={(event) => updateUserProfileField('name', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        入社日
                                        <input className="field-control" type="date" value={selectedProfileDraft.hire_date} onChange={(event) => updateUserProfileField('hire_date', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        管理番号
                                        <input className="field-control" value={selectedProfileDraft.management_number} onChange={(event) => updateUserProfileField('management_number', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        メールアドレス
                                        <input className="field-control" type="email" value={selectedProfileDraft.email} onChange={(event) => updateUserProfileField('email', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        時給
                                        <input className="field-control" type="number" min="0" value={selectedProfileDraft.hourly_wage} onChange={(event) => updateUserProfileField('hourly_wage', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        部署
                                        <select className="field-control" value={selectedProfileDraft.department} onChange={(event) => updateUserProfileField('department', event.target.value)}>
                                            <option value="新今宮">新今宮</option>
                                            <option value="日本橋">日本橋</option>
                                            <option value="南船場">南船場</option>
                                            <option value="阿倍野事務">阿倍野事務</option>
                                            <option value="阿倍野弁当">阿倍野弁当</option>
                                            <option value="在宅">在宅</option>
                                            <option value="フリーケア">フリーケア</option>
                                        </select>
                                    </label>
                                    <label className="field-label">
                                        業務区分
                                        <select className="field-control" value={selectedProfileDraft.business_category} onChange={(event) => updateUserProfileField('business_category', event.target.value)}>
                                            {selectedBusinessCategoryOptions.map((businessCategory) => (
                                                <option key={businessCategory} value={businessCategory}>{businessCategory}</option>
                                            ))}
                                        </select>
                                    </label>
                                    <label className="field-label">
                                        業務形態
                                        <select className="field-control" value={selectedProfileDraft.work_style} onChange={(event) => updateUserProfileField('work_style', event.target.value)}>
                                            <option value="A型">A型</option>
                                            <option value="B型">B型</option>
                                        </select>
                                    </label>
                                    <label className="field-label">
                                        通所上限日数
                                        <select className="field-control" value={selectedProfileDraft.commute_limit_days} onChange={(event) => updateUserProfileField('commute_limit_days', event.target.value)}>
                                            <option value="-8日">-8日</option>
                                            <option value="-4日">-4日</option>
                                        </select>
                                    </label>
                                    <label className="field-label">
                                        有給残日数
                                        <input className="field-control" type="number" min="0" step="0.5" value={selectedProfileDraft.paid_leave_remaining_days} onChange={(event) => updateUserProfileField('paid_leave_remaining_days', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        身長
                                        <input className="field-control" type="number" min="0" step="0.1" value={selectedProfileDraft.height_cm} onChange={(event) => updateUserProfileField('height_cm', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        体重
                                        <input className="field-control" type="number" min="0" step="0.1" value={selectedProfileDraft.weight_kg} onChange={(event) => updateUserProfileField('weight_kg', event.target.value)} />
                                    </label>
                                    <label className="field-label">
                                        性別
                                        <select className="field-control" value={selectedProfileDraft.gender} onChange={(event) => updateUserProfileField('gender', event.target.value)}>
                                            <option value="男">男</option>
                                            <option value="女">女</option>
                                        </select>
                                    </label>
                                </div>
                                <div className="overflow-x-auto rounded-lg border border-slate-200">
                                    <table className="min-w-full text-left text-sm">
                                        <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                            <tr>
                                                <th className="table-cell">曜日</th>
                                                <th className="table-cell">勤務しない</th>
                                                <th className="table-cell">デフォルト出勤時刻</th>
                                                <th className="table-cell">デフォルト退勤時刻</th>
                                                <th className="table-cell">デフォルト休憩時間（分）</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {weekdays.map(([weekday, label]) => {
                                                const setting = selectedProfileDraft.workday_settings[weekday];

                                                return (
                                                    <tr key={weekday} className="hover:bg-slate-50">
                                                        <td className="table-cell font-semibold">{label}</td>
                                                        <td className="table-cell">
                                                            <input
                                                                className="size-4 accent-sky-600"
                                                                type="checkbox"
                                                                checked={!setting.is_working_day}
                                                                onChange={(event) => updateUserProfileWorkday(weekday, 'is_working_day', !event.target.checked)}
                                                            />
                                                        </td>
                                                        <td className="table-cell">
                                                            <input
                                                                className="field-control w-36"
                                                                type="time"
                                                                disabled={!setting.is_working_day}
                                                                value={setting.default_clock_in}
                                                                onChange={(event) => updateUserProfileWorkday(weekday, 'default_clock_in', event.target.value)}
                                                            />
                                                        </td>
                                                        <td className="table-cell">
                                                            <input
                                                                className="field-control w-36"
                                                                type="time"
                                                                disabled={!setting.is_working_day}
                                                                value={setting.default_clock_out}
                                                                onChange={(event) => updateUserProfileWorkday(weekday, 'default_clock_out', event.target.value)}
                                                            />
                                                        </td>
                                                        <td className="table-cell">
                                                            <input
                                                                className="field-control w-32"
                                                                type="number"
                                                                min="0"
                                                                max="600"
                                                                disabled={!setting.is_working_day}
                                                                value={setting.default_break_minutes}
                                                                onChange={(event) => updateUserProfileWorkday(weekday, 'default_break_minutes', event.target.value)}
                                                            />
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                                </>
                            )}

                            <div>
                                <div className="flex flex-wrap gap-2">
                                    <button className="secondary-button" type="button" onClick={saveUserProfile}>
                                        <Save size={17} />
                                        ユーザー情報を保存
                                    </button>
                                    {selectedProfileDraft && (
                                        <div className="flex flex-wrap items-end gap-2">
                                            <label className="field-label w-44">
                                                退職予定日
                                                <input className="field-control" type="date" value={selectedProfileDraft.retirement_date} onChange={(event) => updateUserProfileField('retirement_date', event.target.value)} />
                                            </label>
                                            {selectedProfileUser?.is_retirement_scheduled ? (
                                                <button className="secondary-button" type="button" onClick={cancelSelectedUserRetirement}>
                                                    退職をキャンセルする
                                                </button>
                                            ) : (
                                                <button className="danger-button" type="button" onClick={retireSelectedUser}>
                                                    退職扱いにする
                                                </button>
                                            )}
                                        </div>
                                    )}
                                </div>
                                {userProfileMessage && (
                                    <p className="mt-3 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-sm font-semibold text-sky-800">
                                        {userProfileMessage}
                                    </p>
                                )}
                            </div>
                        </div>
                    </section>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'order' && (
                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">並び替え</h2>
                                <p className="mt-1 text-sm text-slate-500">ユーザー行をドラッグすると自動保存されます。部署間の移動もできます。</p>
                            </div>
                        </div>
                        <div className="grid gap-4 p-5">
                            {usersByDepartment.length === 0 ? (
                                <p className="rounded-md bg-slate-50 px-3 py-8 text-center text-sm text-slate-500">対象ユーザーがいません。</p>
                            ) : usersByDepartment.map(([department, departmentUsers]) => (
                                <section
                                    key={`order-${department}`}
                                    className={`overflow-hidden rounded-lg border border-slate-200 ${draggingOrderDepartment === department ? 'opacity-70 ring-2 ring-sky-200' : ''}`}
                                    onDragOver={(event) => {
                                        event.preventDefault();
                                        event.dataTransfer.dropEffect = 'move';
                                    }}
                                    onDrop={(event) => {
                                        event.preventDefault();
                                        if (draggingOrderDepartment) {
                                            reorderDepartments(draggingOrderDepartment, department);
                                            return;
                                        }
                                        reorderDepartmentUsers(department, event.dataTransfer.getData('text/plain') || draggingOrderUserId);
                                    }}
                                >
                                    <div
                                        className="flex cursor-grab items-center justify-between gap-3 border-b border-slate-200 bg-slate-50 px-4 py-3 active:cursor-grabbing"
                                        draggable
                                        onDragStart={(event) => {
                                            setDraggingOrderDepartment(department);
                                            setDraggingOrderUserId(null);
                                            event.dataTransfer.effectAllowed = 'move';
                                            event.dataTransfer.setData('text/plain', `department:${department}`);
                                        }}
                                        onDragEnd={() => setDraggingOrderDepartment(null)}
                                    >
                                        <h3 className="text-sm font-semibold text-slate-700">{department}</h3>
                                        <span className="text-xs font-semibold text-slate-400">部署ごとドラッグ</span>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="min-w-full text-left text-sm">
                                            <thead className="bg-white text-xs font-semibold uppercase text-slate-500">
                                                <tr>
                                                    <th className="table-cell">移動</th>
                                                    <th className="table-cell">氏名</th>
                                                    <th className="table-cell">管理番号</th>
                                                    <th className="table-cell">メールアドレス</th>
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {departmentUsers.map((user) => (
                                                    <tr
                                                        key={`order-user-${user.id}`}
                                                        className={String(draggingOrderUserId) === String(user.id) ? 'bg-sky-50 opacity-70' : 'hover:bg-slate-50'}
                                                        draggable
                                                        onDragStart={(event) => {
                                                            setDraggingOrderUserId(user.id);
                                                            setDraggingOrderDepartment(null);
                                                            event.dataTransfer.effectAllowed = 'move';
                                                            event.dataTransfer.setData('text/plain', String(user.id));
                                                        }}
                                                        onDragEnd={() => setDraggingOrderUserId(null)}
                                                        onDragOver={(event) => {
                                                            event.preventDefault();
                                                            event.dataTransfer.dropEffect = 'move';
                                                        }}
                                                        onDrop={(event) => {
                                                            event.preventDefault();
                                                            event.stopPropagation();
                                                            if (draggingOrderDepartment) {
                                                                reorderDepartments(draggingOrderDepartment, department);
                                                                return;
                                                            }
                                                            reorderDepartmentUsers(department, event.dataTransfer.getData('text/plain') || draggingOrderUserId, user.id);
                                                        }}
                                                    >
                                                        <td className="table-cell">
                                                            <span className="inline-flex cursor-grab select-none rounded-md border border-slate-200 bg-white px-2 py-1 text-xs font-semibold text-slate-500 active:cursor-grabbing">
                                                                ドラッグ
                                                            </span>
                                                        </td>
                                                        <td className="table-cell font-medium">
                                                            <span className="inline-flex items-center">
                                                                <span>{user.name}</span>
                                                                <RetirementScheduledBadge user={user} />
                                                            </span>
                                                        </td>
                                                        <td className="table-cell">{user.management_number || '-'}</td>
                                                        <td className="table-cell text-slate-600">{user.email}</td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            ))}
                        </div>
                    </section>
                )}

                {(!isAdmin || (!isStandaloneAdminPage && activeAdminTab === 'requests')) && (
                <section className={`order-20 grid gap-6 ${!isAdmin || showAdminAttendanceRequestForm ? 'xl:grid-cols-[420px_1fr]' : ''}`}>
                    {(!isAdmin || showAdminAttendanceRequestForm) && (
                    <form className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm" onSubmit={submitAttendanceRequest}>
                        <div className="mb-5 flex items-center justify-between gap-3">
                            <div className="flex items-center gap-2">
                                <FileText className="text-sky-700" size={20} />
                                <h2 className="text-lg font-semibold">届出を出す</h2>
                            </div>
                            {isAdmin && (
                                <button
                                    className="secondary-button"
                                    type="button"
                                    onClick={() => setShowAdminAttendanceRequestForm(false)}
                                >
                                    閉じる
                                </button>
                            )}
                        </div>
                        <div className="grid gap-4">
                            {isAdmin ? (
                                <div className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                    {selectedUserName} の届出として送信されます。
                                </div>
                            ) : (
                                <div className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                    {viewer?.name} の届出として送信されます。
                                </div>
                            )}
                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="field-label">
                                    種類
                                    <select className="field-control" value={requestForm.type} onChange={(event) => setRequestForm({ ...requestForm, type: event.target.value })}>
                                        {Object.entries(requestTypes).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="field-label">
                                    対象日
                                    <input className="field-control" type="date" value={requestForm.request_date} onChange={(event) => setRequestForm({ ...requestForm, request_date: event.target.value })} />
                                </label>
                            </div>
                            {(!requestTypeHidesStartTime || !requestTypeHidesEndTime) && (
                                <div className={`grid gap-3 ${requestTypeHidesStartTime || requestTypeHidesEndTime ? 'grid-cols-1' : 'grid-cols-2'}`}>
                                    {!requestTypeHidesStartTime && (
                                    <label className="field-label">
                                        勤務できなかった開始時刻
                                        <input className="field-control" type="time" value={requestForm.start_time} onChange={(event) => setRequestForm({ ...requestForm, start_time: event.target.value })} />
                                    </label>
                                    )}
                                    {!requestTypeHidesEndTime && (
                                    <label className="field-label">
                                        勤務できなかった終了時刻
                                        <input className="field-control" type="time" value={requestForm.end_time} onChange={(event) => setRequestForm({ ...requestForm, end_time: event.target.value })} />
                                    </label>
                                    )}
                                </div>
                            )}
                            <label className="field-label">
                                理由
                                <select className="field-control" value={requestForm.reason_category} onChange={(event) => setRequestForm({ ...requestForm, reason_category: event.target.value })}>
                                    {requestReasonCategories.map((reasonCategory) => (
                                        <option key={reasonCategory} value={reasonCategory}>{reasonCategory}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="field-label">
                                備考
                                <textarea className="field-control min-h-20 resize-y" value={requestForm.reason} onChange={(event) => setRequestForm({ ...requestForm, reason: event.target.value })} />
                            </label>
                            <button className="primary-button" type="submit">
                                <Send size={18} />
                                送信する
                            </button>
                        </div>
                    </form>
                    )}

                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <h2 className="text-lg font-semibold">{isAdmin ? (showAllAttendanceRequests ? '全員届出一覧' : `${selectedUserName} の届出一覧`) : '自分の届出一覧'}</h2>
                            {isAdmin && (
                                <div className="flex flex-wrap gap-2">
                                {!showAdminAttendanceRequestForm && (
                                    <button
                                        className="secondary-button"
                                        type="button"
                                        onClick={() => setShowAdminAttendanceRequestForm(true)}
                                    >
                                        届出を出す
                                    </button>
                                )}
                                <button
                                    className={showAllAttendanceRequests ? 'primary-button' : 'secondary-button'}
                                    type="button"
                                    onClick={() => setShowAllAttendanceRequests((current) => !current)}
                                >
                                    {showAllAttendanceRequests ? '選択ユーザーの届出一覧' : '全員届出一覧'}
                                </button>
                                </div>
                            )}
                        </div>
                        <div className="border-b border-slate-200 px-5 py-3">
                            <div className="flex gap-2 overflow-x-auto pb-1">
                                {attendanceRequestTypeTabs.map(([typeValue, typeLabel]) => (
                                    <button
                                        key={`request-type-tab-${typeValue}`}
                                        className={attendanceRequestTypeFilter === typeValue ? 'primary-button whitespace-nowrap px-3 py-2 text-sm' : 'secondary-button whitespace-nowrap px-3 py-2 text-sm'}
                                        type="button"
                                        onClick={() => setAttendanceRequestTypeFilter(typeValue)}
                                    >
                                        {typeLabel}
                                    </button>
                                ))}
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="提出日時" sortKey="submitted_at" /></th>
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="対象日" sortKey="request_date" /></th>
                                        {isAdmin && <th className="table-cell"><SortableAttendanceRequestHeader label="従業員" sortKey="employee" /></th>}
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="種類" sortKey="type" /></th>
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="勤務できなかった時間" sortKey="time" /></th>
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="理由" sortKey="reason_category" /></th>
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="備考" sortKey="reason" /></th>
                                        {isAdmin && <th className="table-cell"><SortableAttendanceRequestHeader label="管理者" sortKey="admin_checked" /></th>}
                                        {isAdmin && <th className="table-cell"><SortableAttendanceRequestHeader label="サビ管" sortKey="service_manager_checked" /></th>}
                                        <th className="table-cell"><SortableAttendanceRequestHeader label="状態" sortKey="status" /></th>
                                        <th className="table-cell">削除</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {attendanceRequests.length === 0 ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={isAdmin ? 11 : 8}>この月の届出はまだありません。</td></tr>
                                    ) : sortedAttendanceRequests.map((attendanceRequest) => (
                                        <tr key={attendanceRequest.id} className={attendanceRequestRowClass(attendanceRequest)}>
                                            <td className="table-cell font-medium text-slate-700">{attendanceRequest.submitted_at || attendanceRequest.created_at || '-'}</td>
                                            <td className="table-cell font-medium">{attendanceRequest.request_date}</td>
                                            {isAdmin && <td className="table-cell">{attendanceRequest.employee}</td>}
                                            <td className="table-cell">{requestTypes[attendanceRequest.type]}</td>
                                            <td className="table-cell">{attendanceRequest.start_time || attendanceRequest.end_time ? `${attendanceRequest.start_time || '-'}〜${attendanceRequest.end_time || '-'}` : '-'}</td>
                                            <td className="table-cell">{attendanceRequest.reason_category || '-'}</td>
                                            <td className="table-cell max-w-72 truncate text-slate-600">{attendanceRequest.reason || '-'}</td>
                                            {isAdmin && (
                                                <td className="table-cell">
                                                    <input
                                                        aria-label="管理者チェック"
                                                        checked={attendanceRequest.admin_checked}
                                                        className="size-4 accent-sky-600"
                                                        type="checkbox"
                                                        onChange={(event) => updateAttendanceRequestChecks(attendanceRequest, 'admin_checked', event.target.checked)}
                                                    />
                                                </td>
                                            )}
                                            {isAdmin && (
                                                <td className="table-cell">
                                                    <input
                                                        aria-label="サビ管チェック"
                                                        checked={attendanceRequest.service_manager_checked}
                                                        className="size-4 accent-sky-600"
                                                        type="checkbox"
                                                        onChange={(event) => updateAttendanceRequestChecks(attendanceRequest, 'service_manager_checked', event.target.checked)}
                                                    />
                                                </td>
                                            )}
                                            <td className="table-cell">
                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${requestStatusClasses[attendanceRequest.status] ?? requestStatusClasses.pending}`}>
                                                    {requestStatusLabels[attendanceRequest.status] ?? requestStatusLabels.pending}
                                                </span>
                                            </td>
                                            <td className="table-cell">
                                                <button
                                                    className="inline-flex items-center gap-1 rounded-md border border-rose-200 bg-white px-2.5 py-1.5 text-xs font-semibold text-rose-700 shadow-sm hover:bg-rose-50"
                                                    type="button"
                                                    onClick={() => deleteAttendanceRequest(attendanceRequest)}
                                                >
                                                    <Trash2 size={14} />
                                                    削除
                                                </button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination
                            total={attendanceRequestsTotal}
                            page={attendanceRequestsPage}
                            onPageChange={setAttendanceRequestsPage}
                        />
                    </section>
                </section>
                )}

                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'reports' && (
                    <section className="order-25 rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <a className="secondary-button md:order-2" href={`/business-report-history?user_id=${selectedUser}&month=${month}`}>
                                個別の1ヶ月分コメント一覧
                            </a>
                            <div className="grid gap-3 md:flex md:items-center">
                                <h2 className="text-lg font-semibold">業務報告</h2>
                                <label className="field-label md:w-48">
                                    表示日付
                                    <input className="field-control" type="date" value={displayDate} onInput={(event) => updateDisplayDate(event.target.value)} onChange={(event) => updateDisplayDate(event.target.value)} />
                                </label>
                            </div>
                        </div>
                        <div className="p-5">
                            <section className="overflow-hidden rounded-lg border border-slate-200">
                                <div className="border-b border-slate-200 bg-slate-50 px-4 py-3">
                                    <h3 className="text-sm font-semibold">全員の業務報告（{displayDate}）</h3>
                                </div>
                                <div className="overflow-x-auto">
                                    <table className="min-w-full text-left text-sm">
                                        <thead className="bg-white text-xs font-semibold uppercase text-slate-500">
                                            <tr>
                                                <th className="table-cell">名前</th>
                                                <th className="table-cell">業務報告</th>
                                                <th className="table-cell">管理者コメント</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {isBusinessReportsLoading ? (
                                                <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={3}>読み込み中...</td></tr>
                                            ) : todayBusinessReports.length === 0 ? (
                                                <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={3}>この日の業務報告はまだありません。</td></tr>
                                            ) : paginatedTodayBusinessReports.map((report) => (
                                                <tr key={`today-report-${report.user_id}`} className="hover:bg-slate-50">
                                                    <td className="table-cell font-medium">
                                                        <span className="inline-flex items-center">
                                                            <span>{report.employee}</span>
                                                            <RetirementScheduledBadge user={usersById.get(String(report.user_id))} />
                                                        </span>
                                                    </td>
                                                    <td className="table-cell max-w-72 whitespace-pre-wrap text-slate-700">{report.note || '-'}</td>
                                                    <td className="table-cell max-w-80 whitespace-pre-wrap text-slate-600">{report.admin_comment}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                <Pagination total={todayBusinessReports.length} page={todayBusinessReportsPage} onPageChange={setTodayBusinessReportsPage} />
                            </section>
                        </div>
                    </section>
                )}

                {isBusinessReportHistoryPage && isAdmin && (
                    <section className="order-25 rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">{selectedUserName} / {month} の業務報告コメント一覧</h2>
                                <p className="mt-1 text-sm text-slate-500">対象ユーザーの1ヶ月分の業務報告と管理者コメントを表示します。</p>
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <a
                                    className="secondary-button"
                                    href={`/api/attendance-records/business-reports/pdf?user_id=${encodeURIComponent(selectedUser)}&month=${encodeURIComponent(month)}`}
                                >
                                    <Download size={17} />
                                    業務報告PDF
                                </a>
                                <a className="secondary-button" href="/">
                                    管理者画面へ戻る
                                </a>
                            </div>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="table-cell">日付</th>
                                        <th className="table-cell">業務報告</th>
                                        <th className="table-cell">管理者コメント</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {isBusinessReportsLoading ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={3}>読み込み中...</td></tr>
                                    ) : monthlyBusinessReports.length === 0 ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={3}>対象月の業務報告コメントはまだありません。</td></tr>
                                    ) : paginatedMonthlyBusinessReports.map((report) => (
                                        <tr key={`monthly-report-history-${report.work_date}`} className="hover:bg-slate-50">
                                            <td className="table-cell font-medium">{report.work_date}</td>
                                            <td className="table-cell max-w-96 whitespace-pre-wrap text-slate-700">{report.note}</td>
                                            <td className="table-cell max-w-[32rem] whitespace-pre-wrap text-slate-600">{report.admin_comment}</td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination total={monthlyBusinessReports.length} page={monthlyBusinessReportsPage} onPageChange={setMonthlyBusinessReportsPage} />
                    </section>
                )}

                {(!isAdmin || (isHistoryPage && isAdmin && showAttendanceRecordForm) || (!isStandaloneAdminPage && activeAdminTab === 'attendance')) && (
                <section className="order-10 grid gap-6">
                    {showAttendanceRecordForm && (
                    <div className="fixed inset-0 z-50 bg-transparent" onClick={resetForm}>
                    <form className="attendance-edit-panel h-screen w-[min(92vw,420px)] overflow-y-auto border-r border-slate-200 bg-white p-5 shadow-2xl" onClick={(event) => event.stopPropagation()} onSubmit={submitRecord}>
                        <div className="mb-5 flex items-center justify-between gap-3">
                            <h2 className="text-lg font-semibold">勤怠を修正</h2>
                            <button className="secondary-button" type="button" onClick={resetForm}>
                                閉じる
                            </button>
                        </div>

                        <div className="grid gap-4">
                            {isAdmin ? (
                                <label className="field-label">
                                    従業員
                                    <select className="field-control" value={form.user_id} onChange={(event) => setForm({ ...form, user_id: event.target.value })}>
                                        {users.map((user) => (
                                            <option key={user.id} value={user.id}>{user.name}</option>
                                        ))}
                                    </select>
                                </label>
                            ) : (
                                <div className="rounded-md bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                                    {viewer?.name} の勤怠として保存されます。
                                </div>
                            )}
                            <label className="field-label">
                                日付
                                <input className="field-control" type="date" value={form.work_date} onChange={(event) => setForm({ ...form, work_date: event.target.value })} />
                                <ErrorText errors={errors.work_date} />
                            </label>
                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="field-label">
                                    出勤
                                    <input className="field-control" type="time" value={form.clock_in} onChange={(event) => setForm({ ...form, clock_in: event.target.value })} />
                                </label>
                                <label className="field-label">
                                    退勤
                                    <input className="field-control" type="time" value={form.clock_out} onChange={(event) => setForm({ ...form, clock_out: event.target.value })} />
                                    <ErrorText errors={errors.clock_out} />
                                </label>
                                <label className="field-label">
                                    休憩分
                                    <input className="field-control" type="number" min="0" max="600" value={form.break_minutes} onChange={(event) => setForm({ ...form, break_minutes: event.target.value })} />
                                </label>
                            </div>
                            <div className="grid gap-3 md:grid-cols-3">
                                <label className="field-label">
                                    申告出勤
                                    <input className="field-control" type="time" value={form.declared_clock_in} onChange={(event) => setForm({ ...form, declared_clock_in: event.target.value })} />
                                </label>
                                <label className="field-label">
                                    申告退勤
                                    <input className="field-control" type="time" value={form.declared_clock_out} onChange={(event) => setForm({ ...form, declared_clock_out: event.target.value })} />
                                    <ErrorText errors={errors.declared_clock_out} />
                                </label>
                                <label className="field-label">
                                    申告休憩（分）
                                    <input className="field-control" type="number" min="0" max="600" value={form.declared_break_minutes} onChange={(event) => setForm({ ...form, declared_break_minutes: event.target.value })} />
                                    <ErrorText errors={errors.declared_break_minutes} />
                                </label>
                            </div>
                            <div className="grid gap-3 md:grid-cols-2">
                                <label className="field-label">
                                    状態
                                    <select className="field-control" value={form.status} onChange={(event) => setForm({ ...form, status: event.target.value })}>
                                        {Object.entries(statusLabels).map(([value, label]) => (
                                            <option key={value} value={value}>{label}</option>
                                        ))}
                                    </select>
                                </label>
                                <label className="field-label">
                                    勤務場所
                                    <select
                                        className="field-control"
                                        value={form.work_location}
                                        onChange={(event) => setForm({
                                            ...form,
                                            work_location: event.target.value,
                                            meal_percentage: event.target.value === 'home' ? '' : form.meal_percentage,
                                            missed_meal: event.target.value === 'home' ? false : form.missed_meal,
                                        })}
                                    >
                                        <option value="">未選択</option>
                                        <option value="office">通所</option>
                                        <option value="home">在宅</option>
                                    </select>
                                    <ErrorText errors={errors.work_location} />
                                </label>
                            </div>
                            {form.work_location !== 'home' && (
                                <div className="grid gap-3 md:grid-cols-2">
                                    <label className="field-label">
                                        食事割合（0〜100％）
                                        <input
                                            className="field-control"
                                            type="number"
                                            min="0"
                                            max="100"
                                            value={form.meal_percentage}
                                            onChange={(event) => setForm({ ...form, meal_percentage: event.target.value })}
                                        />
                                        <ErrorText errors={errors.meal_percentage} />
                                    </label>
                                    <label className="field-label">
                                        欠食
                                        <span className="inline-flex min-h-[42px] items-center gap-2 rounded-md border border-slate-300 bg-white px-3">
                                            <input
                                                type="checkbox"
                                                checked={Boolean(form.missed_meal)}
                                                onChange={(event) => setForm({ ...form, missed_meal: event.target.checked })}
                                            />
                                            <span className="text-sm font-medium text-slate-700">欠食あり</span>
                                        </span>
                                    </label>
                                </div>
                            )}
                            {attendanceRequestLinkedStatuses.includes(form.status) && (
                                <div className="grid gap-3">
                                    {['late', 'early_leave'].includes(form.status) && (
                                        <div className="grid gap-3 md:grid-cols-2">
                                            <label className="field-label">
                                                勤務できなかった開始時刻
                                                <input className="field-control" type="time" value={form.request_absent_start_time} onChange={(event) => setForm({ ...form, request_absent_start_time: event.target.value })} />
                                                <ErrorText errors={errors.request_absent_start_time} />
                                            </label>
                                            <label className="field-label">
                                                勤務できなかった終了時刻
                                                <input className="field-control" type="time" value={form.request_absent_end_time} onChange={(event) => setForm({ ...form, request_absent_end_time: event.target.value })} />
                                                <ErrorText errors={errors.request_absent_end_time} />
                                            </label>
                                        </div>
                                    )}
                                    {form.status === 'late_and_early_leave' && (
                                        <div className="grid gap-3">
                                            <div className="grid gap-3 md:grid-cols-2">
                                                <label className="field-label">
                                                    遅刻の勤務不可開始
                                                    <input className="field-control" type="time" value={form.request_late_start_time} onChange={(event) => setForm({ ...form, request_late_start_time: event.target.value })} />
                                                    <ErrorText errors={errors.request_late_start_time} />
                                                </label>
                                                <label className="field-label">
                                                    遅刻の勤務不可終了
                                                    <input className="field-control" type="time" value={form.request_late_end_time} onChange={(event) => setForm({ ...form, request_late_end_time: event.target.value })} />
                                                    <ErrorText errors={errors.request_late_end_time} />
                                                </label>
                                            </div>
                                            <div className="grid gap-3 md:grid-cols-2">
                                                <label className="field-label">
                                                    早退の勤務不可開始
                                                    <input className="field-control" type="time" value={form.request_early_leave_start_time} onChange={(event) => setForm({ ...form, request_early_leave_start_time: event.target.value })} />
                                                    <ErrorText errors={errors.request_early_leave_start_time} />
                                                </label>
                                                <label className="field-label">
                                                    早退の勤務不可終了
                                                    <input className="field-control" type="time" value={form.request_early_leave_end_time} onChange={(event) => setForm({ ...form, request_early_leave_end_time: event.target.value })} />
                                                    <ErrorText errors={errors.request_early_leave_end_time} />
                                                </label>
                                            </div>
                                        </div>
                                    )}
                                    <div className="grid gap-3 md:grid-cols-2">
                                        <label className="field-label">
                                            理由
                                            <select className="field-control" value={form.request_reason_category} onChange={(event) => setForm({ ...form, request_reason_category: event.target.value })}>
                                                {requestReasonCategories.map((reasonCategory) => (
                                                    <option key={reasonCategory} value={reasonCategory}>{reasonCategory}</option>
                                                ))}
                                            </select>
                                            <ErrorText errors={errors.request_reason_category} />
                                        </label>
                                        <label className="field-label">
                                            備考
                                            <textarea className="field-control min-h-20 resize-y" value={form.request_reason} onChange={(event) => setForm({ ...form, request_reason: event.target.value })} />
                                            <ErrorText errors={errors.request_reason} />
                                        </label>
                                    </div>
                                    {isAdmin && (form.attendance_requests ?? []).length > 0 && (
                                        <div className="rounded-md border border-sky-100 bg-sky-50/60 p-3">
                                            <p className="text-sm font-semibold text-slate-800">届出チェック</p>
                                            <div className="mt-3 grid gap-2">
                                                {(form.attendance_requests ?? []).map((attendanceRequest) => (
                                                    <div key={attendanceRequest.id} className="grid gap-2 rounded-md border border-white bg-white px-3 py-2 text-sm shadow-sm md:grid-cols-[1fr_auto_auto] md:items-center">
                                                        <div className="font-medium text-slate-700">
                                                            {requestTypes[attendanceRequest.type] ?? attendanceRequest.type}
                                                            <span className="ml-2 text-xs font-normal text-slate-400">{attendanceRequest.request_date}</span>
                                                        </div>
                                                        <label className="inline-flex items-center gap-2 font-medium text-slate-700">
                                                            <input
                                                                className="size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-200"
                                                                type="checkbox"
                                                                checked={Boolean(attendanceRequest.admin_checked)}
                                                                onChange={(event) => updateFormAttendanceRequestCheck(attendanceRequest, 'admin_checked', event.target.checked)}
                                                            />
                                                            管理者チェック
                                                        </label>
                                                        <label className="inline-flex items-center gap-2 font-medium text-slate-700">
                                                            <input
                                                                className="size-4 rounded border-slate-300 text-sky-700 focus:ring-sky-200"
                                                                type="checkbox"
                                                                checked={Boolean(attendanceRequest.service_manager_checked)}
                                                                onChange={(event) => updateFormAttendanceRequestCheck(attendanceRequest, 'service_manager_checked', event.target.checked)}
                                                            />
                                                            サビ管チェック
                                                        </label>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            )}
                            <label className="field-label">
                                業務報告
                                <textarea className="field-control min-h-24 resize-y" value={form.note} onChange={(event) => setForm({ ...form, note: event.target.value })} />
                            </label>
                            {isAdmin && (
                                <label className="field-label">
                                    管理者コメント
                                    <textarea className="field-control min-h-24 resize-y" value={form.admin_comment} onChange={(event) => setForm({ ...form, admin_comment: event.target.value })} />
                                </label>
                            )}
                            <button className="primary-button" type="submit">
                                <Save size={18} />
                                {form.id ? '更新する' : '追加する'}
                            </button>
                        </div>
                    </form>
                    </div>
                    )}

                    {!isHistoryPage && (
                    <section className="rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5">
                            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <div className="grid gap-3 md:flex md:items-center">
                                    <h2 className="text-lg font-semibold">{isAdmin ? `全員勤怠一覧（${displayDate}）` : `${selectedUserName} / ${month} の勤怠一覧`}</h2>
                                    {isAdmin && (
                                        <label className="field-label md:w-48">
                                            表示日付
                                            <input className="field-control" type="date" value={displayDate} onInput={(event) => updateDisplayDate(event.target.value)} onChange={(event) => updateDisplayDate(event.target.value)} />
                                        </label>
                                    )}
                                </div>
                                {isAdmin && !isHistoryPage && (
                                    <a
                                        className="secondary-button"
                                        href={selectedHistoryDepartment
                                            ? `/attendance-history?department=${encodeURIComponent(selectedHistoryDepartment)}&month=${encodeURIComponent(month)}`
                                            : `/attendance-history?user_id=${encodeURIComponent(selectedUser || form.user_id || '')}&month=${encodeURIComponent(month)}`}
                                    >
                                        対象月の勤怠一覧
                                    </a>
                                )}
                                {!isAdmin && (
                                    <div className="flex flex-wrap justify-end gap-2">
                                        {clockStatus.can_cancel_clock_in ? (
                                            <button className="secondary-button" type="button" onClick={() => cancelClock('in')}>
                                                <Clock size={17} />
                                                出勤取消
                                            </button>
                                        ) : (
                                            <button
                                                className="secondary-button disabled:cursor-not-allowed disabled:opacity-50"
                                                type="button"
                                                disabled={!clockStatus.can_clock_in}
                                                title={clockStatus.clock_in_disabled_reason || '出勤'}
                                                onClick={() => clock('in')}
                                            >
                                                <Clock size={17} />
                                                出勤
                                            </button>
                                        )}
                                        {clockStatus.can_cancel_clock_out ? (
                                            <button className="secondary-button" type="button" onClick={() => cancelClock('out')}>
                                                <Clock size={17} />
                                                退勤取消
                                            </button>
                                        ) : (
                                            <button
                                                className="secondary-button disabled:cursor-not-allowed disabled:opacity-50"
                                                type="button"
                                                disabled={!canPressClockOutButton()}
                                                title={clockStatus.clock_out_disabled_reason || '退勤'}
                                                onClick={openClockOutModal}
                                            >
                                                <Clock size={17} />
                                                退勤
                                            </button>
                                        )}
                                    </div>
                                )}
                            </div>
                        </div>

                        <div className="overflow-x-auto">
                            <table className="min-w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        {isAdmin && <th className="table-cell">従業員</th>}
                                        {!isAdmin && <th className="table-cell">日付</th>}
                                        <th className="table-cell">出勤</th>
                                        <th className="table-cell">退勤</th>
                                        <th className="table-cell">休憩</th>
                                        <th className="table-cell">勤務場所</th>
                                        <th className="table-cell">状態</th>
                                        <th className="table-cell">業務報告</th>
                                        <th className="table-cell">管理者コメント</th>
                                        <th className="table-cell text-right">操作</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {isLoading ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={9}>読み込み中...</td></tr>
                                    ) : records.length === 0 ? (
                                        <tr><td className="px-5 py-10 text-center text-slate-500" colSpan={9}>{isAdmin ? 'この日の勤怠はまだありません。' : 'この月の勤怠はまだありません。'}</td></tr>
                                    ) : displayedRecords.map((record) => (
                                        <tr
                                            key={record.id ?? `empty-${record.user_id}`}
                                            className={attendanceRecordRowClass(record)}
                                        >
                                            {isAdmin && (
                                                <td className="table-cell font-medium">
                                                    {(() => {
                                                        const recordUser = usersById.get(String(record.user_id));

                                                        return (
                                                            <div className="grid gap-1">
                                                                <span className="inline-flex items-center">
                                                                    {recordUser?.management_number && (
                                                                        <span className="mr-2 text-xs font-normal text-slate-400">{recordUser.management_number}</span>
                                                                    )}
                                                                    <span>{record.employee}</span>
                                                                    <RetirementScheduledBadge user={recordUser} />
                                                                </span>
                                                                <span className="text-xs font-normal text-slate-500">
                                                                    {defaultWorkTimeLabel(recordUser, record.work_date || displayDate)}
                                                                </span>
                                                            </div>
                                                        );
                                                    })()}
                                                </td>
                                            )}
                                            {!isAdmin && <td className="table-cell font-medium">{record.work_date}</td>}
                                            <td className="table-cell">{record.clock_in || '-'}</td>
                                            <td className="table-cell">{record.clock_out || '-'}</td>
                                            <td className="table-cell">{record.break_minutes}分</td>
                                            <td className="table-cell">{record.work_location_label || '-'}</td>
                                            <td className="table-cell">
                                                <span className={`inline-flex rounded-full px-2.5 py-1 text-xs font-semibold ring-1 ${statusClasses[record.display_status_type] ?? statusClasses[record.status]}`}>
                                                    {record.display_status ?? statusLabels[record.status]}
                                                </span>
                                            </td>
                                            <td className="table-cell min-w-64 text-slate-600">
                                                {record.can_report_edit ? (
                                                    <textarea
                                                        className="field-control min-h-16 resize-y"
                                                        placeholder="業務報告を入力"
                                                        value={businessReportDrafts[record.id] ?? record.note ?? ''}
                                                        onChange={(event) => scheduleBusinessReportSave(record, event.target.value)}
                                                    />
                                                ) : record.note ? (
                                                    <span className="block max-w-64 truncate">{record.note}</span>
                                                ) : (
                                                    '-'
                                                )}
                                            </td>
                                            <td className="table-cell max-w-80 whitespace-pre-wrap text-slate-600">{record.admin_comment || '-'}</td>
                                            <td className="table-cell">
                                                <div className="flex justify-end gap-2">
                                                    <button
                                                        className="icon-button disabled:cursor-not-allowed disabled:opacity-40"
                                                        type="button"
                                                        onClick={() => editRecord(record)}
                                                        title={record.can_edit ? '修正' : '直近3日以内の勤怠のみ修正できます'}
                                                        disabled={!record.can_edit}
                                                    >
                                                        <Pencil size={16} />
                                                    </button>
                                                    <button
                                                        className="icon-button danger disabled:cursor-not-allowed disabled:opacity-40"
                                                        type="button"
                                                        onClick={() => deleteRecord(record)}
                                                        title={record.can_edit ? '削除' : '直近3日以内の勤怠のみ削除できます'}
                                                        disabled={!record.id || !record.can_edit}
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        {!isHistoryPage && <Pagination total={records.length} page={recordsPage} onPageChange={setRecordsPage} />}
                    </section>
                    )}
                </section>
                )}
                {isAdmin && !isStandaloneAdminPage && activeAdminTab === 'retired' && (
                    <section className="order-40 rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 md:flex-row md:items-center md:justify-between">
                            <div>
                                <h2 className="text-lg font-semibold">退職者一覧</h2>
                                <p className="mt-1 text-sm text-slate-500">退職扱いにしたユーザーの過去の打刻と届出を確認できます。</p>
                            </div>
                            {selectedRetiredUser && (
                                <div className="flex flex-wrap gap-2">
                                    <button className="secondary-button" type="button" onClick={restoreRetiredUser}>
                                        復職
                                    </button>
                                    <button className="danger-button" type="button" onClick={forceDeleteRetiredUser}>
                                        DBから完全に削除
                                    </button>
                                </div>
                            )}
                        </div>
                        <div className="grid gap-4 p-5 xl:grid-cols-[280px_1fr]">
                            <div className="rounded-lg border border-slate-200">
                                <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">
                                    退職者名
                                </div>
                                <div className="max-h-80 overflow-y-auto p-2">
                                    {isRetiredUsersLoading ? (
                                        <p className="px-3 py-6 text-sm text-slate-500">読み込み中...</p>
                                    ) : retiredUsers.length === 0 ? (
                                        <p className="px-3 py-6 text-sm text-slate-500">退職者はいません。</p>
                                    ) : retiredUsers.map((user) => (
                                        <button
                                            key={`retired-user-${user.id}`}
                                            className={String(user.id) === String(selectedRetiredUserId) ? 'primary-button mb-2 w-full justify-start px-3 py-2 text-sm' : 'secondary-button mb-2 w-full justify-start px-3 py-2 text-sm'}
                                            type="button"
                                            onClick={() => selectRetiredUser(user.id)}
                                        >
                                            <span className="truncate">{user.name}</span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-4">
                                {selectedRetiredUser ? (
                                    <>
                                    <div className="rounded-md border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700">
                                        <span className="font-semibold">{selectedRetiredUser.name}</span>
                                        {selectedRetiredUser.retirement_date && <span className="ml-3">退職予定日: {selectedRetiredUser.retirement_date}</span>}
                                    </div>
                                    <section className="overflow-hidden rounded-lg border border-slate-200">
                                        <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">過去の打刻一覧</div>
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full text-left text-sm">
                                                <thead className="bg-white text-xs font-semibold uppercase text-slate-500">
                                                    <tr>
                                                        <th className="table-cell">日付</th>
                                                        <th className="table-cell">出勤</th>
                                                        <th className="table-cell">退勤</th>
                                                        <th className="table-cell">休憩</th>
                                                        <th className="table-cell">実働</th>
                                                        <th className="table-cell">勤務場所</th>
                                                        <th className="table-cell">状態</th>
                                                        <th className="table-cell">業務報告</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {retiredRecords.length === 0 ? (
                                                        <tr><td className="px-5 py-8 text-center text-slate-500" colSpan={8}>打刻はありません。</td></tr>
                                                    ) : retiredRecords.map((record) => (
                                                        <tr key={`retired-record-${record.id}`} className="hover:bg-slate-50">
                                                            <td className="table-cell font-medium">{record.work_date}</td>
                                                            <td className="table-cell">{record.clock_in || '-'}</td>
                                                            <td className="table-cell">{record.clock_out || '-'}</td>
                                                            <td className="table-cell">{record.break_minutes}分</td>
                                                            <td className="table-cell">{minutesToHours(record.worked_minutes)}</td>
                                                            <td className="table-cell">{record.work_location_label || '-'}</td>
                                                            <td className="table-cell">{statusLabels[record.status] ?? record.status}</td>
                                                            <td className="table-cell max-w-72 truncate text-slate-600">{record.note || '-'}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                    <section className="overflow-hidden rounded-lg border border-slate-200">
                                        <div className="border-b border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">届出一覧</div>
                                        <div className="overflow-x-auto">
                                            <table className="min-w-full text-left text-sm">
                                                <thead className="bg-white text-xs font-semibold uppercase text-slate-500">
                                                    <tr>
                                                        <th className="table-cell">提出日時</th>
                                                        <th className="table-cell">対象日</th>
                                                        <th className="table-cell">種類</th>
                                                        <th className="table-cell">勤務できなかった時間</th>
                                                        <th className="table-cell">理由</th>
                                                        <th className="table-cell">備考</th>
                                                        <th className="table-cell">状態</th>
                                                    </tr>
                                                </thead>
                                                <tbody className="divide-y divide-slate-100">
                                                    {retiredRequests.length === 0 ? (
                                                        <tr><td className="px-5 py-8 text-center text-slate-500" colSpan={7}>届出はありません。</td></tr>
                                                    ) : retiredRequests.map((attendanceRequest) => (
                                                        <tr key={`retired-request-${attendanceRequest.id}`} className="hover:bg-slate-50">
                                                            <td className="table-cell">{attendanceRequest.submitted_at || '-'}</td>
                                                            <td className="table-cell font-medium">{attendanceRequest.request_date}</td>
                                                            <td className="table-cell">{requestTypes[attendanceRequest.type] ?? attendanceRequest.type}</td>
                                                            <td className="table-cell">{attendanceRequest.start_time || attendanceRequest.end_time ? `${attendanceRequest.start_time || '-'}〜${attendanceRequest.end_time || '-'}` : '-'}</td>
                                                            <td className="table-cell">{attendanceRequest.reason_category || '-'}</td>
                                                            <td className="table-cell max-w-72 truncate text-slate-600">{attendanceRequest.reason || '-'}</td>
                                                            <td className="table-cell">{requestStatusLabels[attendanceRequest.status] ?? attendanceRequest.status}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    </section>
                                    </>
                                ) : (
                                    <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                                        退職者を選択すると詳細が表示されます。
                                    </div>
                                )}
                            </div>
                        </div>
                    </section>
                )}
            </div>
            {showClockOutModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/40 p-4">
                    <form
                        className="w-full max-w-md rounded-lg border border-slate-200 bg-white p-5 shadow-xl"
                        onSubmit={(event) => {
                            event.preventDefault();
                            clock('out', clockOutDraft);
                        }}
                    >
                        <div className="mb-5">
                            <h2 className="text-lg font-semibold">勤務時間を申告</h2>
                            <p className="mt-1 text-sm text-slate-500">デフォルトの勤務時間が入っています。必要に応じて修正してください。</p>
                        </div>
                        <div className="grid gap-4">
                            <div className="grid grid-cols-2 gap-3">
                                <label className="field-label">
                                    申告出勤
                                    <input
                                        className="field-control"
                                        type="time"
                                        value={clockOutDraft.declared_clock_in}
                                        onChange={(event) => setClockOutDraft({ ...clockOutDraft, declared_clock_in: event.target.value })}
                                    />
                                </label>
                                <label className="field-label">
                                    申告退勤
                                    <input
                                        className="field-control"
                                        type="time"
                                        value={clockOutDraft.declared_clock_out}
                                        onChange={(event) => setClockOutDraft({ ...clockOutDraft, declared_clock_out: event.target.value })}
                                    />
                                </label>
                                <label className="field-label">
                                    申告休憩（分）
                                    <input
                                        className="field-control"
                                        type="number"
                                        min="0"
                                        max="600"
                                        value={clockOutDraft.declared_break_minutes}
                                        onChange={(event) => setClockOutDraft({ ...clockOutDraft, declared_break_minutes: event.target.value })}
                                    />
                                </label>
                                <label className="field-label">
                                    勤務場所
                                    <select
                                        className="field-control"
                                        value={clockOutDraft.work_location}
                                        onChange={(event) => setClockOutDraft({
                                            ...clockOutDraft,
                                            work_location: event.target.value,
                                            meal_percentage: event.target.value === 'home' ? '' : clockOutDraft.meal_percentage,
                                            missed_meal: event.target.value === 'home' ? false : clockOutDraft.missed_meal,
                                        })}
                                    >
                                        <option value="office">通所</option>
                                        <option value="home">在宅</option>
                                    </select>
                                </label>
                                {clockOutDraft.work_location !== 'home' && (
                                    <>
                                        <label className="field-label">
                                            食事割合（0〜100％）
                                            <input
                                                className="field-control"
                                                type="number"
                                                min="0"
                                                max="100"
                                                value={clockOutDraft.meal_percentage}
                                                onChange={(event) => setClockOutDraft({ ...clockOutDraft, meal_percentage: event.target.value })}
                                            />
                                        </label>
                                        <label className="field-label">
                                            欠食
                                            <span className="inline-flex min-h-[42px] items-center gap-2 rounded-md border border-slate-300 bg-white px-3">
                                                <input
                                                    type="checkbox"
                                                    checked={Boolean(clockOutDraft.missed_meal)}
                                                    onChange={(event) => setClockOutDraft({ ...clockOutDraft, missed_meal: event.target.checked })}
                                                />
                                                <span className="text-sm font-medium text-slate-700">欠食あり</span>
                                            </span>
                                        </label>
                                    </>
                                )}
                            </div>
                            <div className="flex justify-end gap-2">
                                <button className="secondary-button" type="button" onClick={() => setShowClockOutModal(false)}>
                                    キャンセル
                                </button>
                                <button className="primary-button" type="submit">
                                    <Clock size={17} />
                                    退勤する
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            )}
        </main>
    );
}

function Metric({ icon: Icon, label, value }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
            <div className="mb-3 inline-flex size-9 items-center justify-center rounded-md bg-sky-50 text-sky-700">
                <Icon size={19} />
            </div>
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className="mt-1 break-words text-2xl font-semibold text-slate-950">{value}</p>
        </div>
    );
}

function ErrorText({ errors }) {
    if (!errors?.length) return null;

    return <span className="text-xs font-medium text-rose-600">{errors[0]}</span>;
}

function Pagination({ total, page, onPageChange, perPage = pageSize }) {
    if (total <= perPage) return null;

    const lastPage = Math.max(1, Math.ceil(total / perPage));
    const currentPage = Math.min(page, lastPage);
    const from = ((currentPage - 1) * perPage) + 1;
    const to = Math.min(currentPage * perPage, total);

    return (
        <div className="flex flex-col gap-3 border-t border-slate-200 px-5 py-4 text-sm text-slate-600 sm:flex-row sm:items-center sm:justify-between">
            <span>{from}〜{to} / {total}件</span>
            <div className="flex gap-2">
                <button
                    className="secondary-button px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    disabled={currentPage <= 1}
                    onClick={() => onPageChange(currentPage - 1)}
                >
                    前へ
                </button>
                <span className="rounded-md border border-slate-200 bg-white px-3 py-1.5 font-semibold text-slate-700">
                    {currentPage} / {lastPage}
                </span>
                <button
                    className="secondary-button px-3 py-1.5 text-sm disabled:cursor-not-allowed disabled:opacity-50"
                    type="button"
                    disabled={currentPage >= lastPage}
                    onClick={() => onPageChange(currentPage + 1)}
                >
                    次へ
                </button>
            </div>
        </div>
    );
}

createRoot(document.getElementById('app')).render(<AttendanceApp />);

