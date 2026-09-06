// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Icon registry for dossiq (ADR-077 semantic icon vocabulary).
//
// CnAppNav, CnIcon, CnIndexPage / CnDetailPage headers and empty states resolve
// an `icon` by PascalCase name through the registry that `registerIcons()`
// populates. A name that is not registered renders NO icon in the navigation —
// not a fallback glyph — so this file must cover every `icon` the manifests and
// register files name. Keep it in sync when you add a menu entry.
//
// Generated from the app's own manifests; every name is verified to exist in
// vue-material-design-icons.

import Account from 'vue-material-design-icons/Account.vue'
import AccountArrowRight from 'vue-material-design-icons/AccountArrowRight.vue'
import AccountClock from 'vue-material-design-icons/AccountClock.vue'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountHardHat from 'vue-material-design-icons/AccountHardHat.vue'
import AccountKeyOutline from 'vue-material-design-icons/AccountKeyOutline.vue'
import AccountMultipleOutline from 'vue-material-design-icons/AccountMultipleOutline.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AccountPlusOutline from 'vue-material-design-icons/AccountPlusOutline.vue'
import AccountSwitch from 'vue-material-design-icons/AccountSwitch.vue'
import AccountSwitchOutline from 'vue-material-design-icons/AccountSwitchOutline.vue'
import AccountTieOutline from 'vue-material-design-icons/AccountTieOutline.vue'
import AccountVoice from 'vue-material-design-icons/AccountVoice.vue'
import Alarm from 'vue-material-design-icons/Alarm.vue'
import AlertCircleOutline from 'vue-material-design-icons/AlertCircleOutline.vue'
import AlertOctagonOutline from 'vue-material-design-icons/AlertOctagonOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import ArrowUpBoldCircle from 'vue-material-design-icons/ArrowUpBoldCircle.vue'
import BadgeAccountOutline from 'vue-material-design-icons/BadgeAccountOutline.vue'
import BankTransfer from 'vue-material-design-icons/BankTransfer.vue'
import BellCogOutline from 'vue-material-design-icons/BellCogOutline.vue'
import BellPlusOutline from 'vue-material-design-icons/BellPlusOutline.vue'
import BellRing from 'vue-material-design-icons/BellRing.vue'
import BellRingOutline from 'vue-material-design-icons/BellRingOutline.vue'
import BookOpenPageVariant from 'vue-material-design-icons/BookOpenPageVariant.vue'
import BookOpenVariantOutline from 'vue-material-design-icons/BookOpenVariantOutline.vue'
import BriefcaseAccountOutline from 'vue-material-design-icons/BriefcaseAccountOutline.vue'
import BriefcaseOutline from 'vue-material-design-icons/BriefcaseOutline.vue'
import BriefcaseVariantOutline from 'vue-material-design-icons/BriefcaseVariantOutline.vue'
import Calculator from 'vue-material-design-icons/Calculator.vue'
import Calendar from 'vue-material-design-icons/Calendar.vue'
import CalendarTextOutline from 'vue-material-design-icons/CalendarTextOutline.vue'
import CameraOutline from 'vue-material-design-icons/CameraOutline.vue'
import Cash from 'vue-material-design-icons/Cash.vue'
import CashMultiple from 'vue-material-design-icons/CashMultiple.vue'
import CashRefund from 'vue-material-design-icons/CashRefund.vue'
import CashRegister from 'vue-material-design-icons/CashRegister.vue'
import ChartBoxOutline from 'vue-material-design-icons/ChartBoxOutline.vue'
import ChartLine from 'vue-material-design-icons/ChartLine.vue'
import ChartSankey from 'vue-material-design-icons/ChartSankey.vue'
import CheckboxMarkedCircleOutline from 'vue-material-design-icons/CheckboxMarkedCircleOutline.vue'
import CheckboxMarkedOutline from 'vue-material-design-icons/CheckboxMarkedOutline.vue'
import CheckboxOutline from 'vue-material-design-icons/CheckboxOutline.vue'
import CheckCircleOutline from 'vue-material-design-icons/CheckCircleOutline.vue'
import CheckDecagram from 'vue-material-design-icons/CheckDecagram.vue'
import ClipboardAccountOutline from 'vue-material-design-icons/ClipboardAccountOutline.vue'
import ClipboardCheckMultipleOutline from 'vue-material-design-icons/ClipboardCheckMultipleOutline.vue'
import ClipboardCheckOutline from 'vue-material-design-icons/ClipboardCheckOutline.vue'
import ClipboardList from 'vue-material-design-icons/ClipboardList.vue'
import ClipboardListOutline from 'vue-material-design-icons/ClipboardListOutline.vue'
import ClipboardPulseOutline from 'vue-material-design-icons/ClipboardPulseOutline.vue'
import ClipboardSearchOutline from 'vue-material-design-icons/ClipboardSearchOutline.vue'
import ClipboardTextOutline from 'vue-material-design-icons/ClipboardTextOutline.vue'
import ClockAlertOutline from 'vue-material-design-icons/ClockAlertOutline.vue'
import ClockPlusOutline from 'vue-material-design-icons/ClockPlusOutline.vue'
import Close from 'vue-material-design-icons/Close.vue'
import CloudUploadOutline from 'vue-material-design-icons/CloudUploadOutline.vue'
import Cog from 'vue-material-design-icons/Cog.vue'
import CogOutline from 'vue-material-design-icons/CogOutline.vue'
import CommentOutline from 'vue-material-design-icons/CommentOutline.vue'
import CommentQuestionOutline from 'vue-material-design-icons/CommentQuestionOutline.vue'
import CommentTextOutline from 'vue-material-design-icons/CommentTextOutline.vue'
import Connection from 'vue-material-design-icons/Connection.vue'
import Creation from 'vue-material-design-icons/Creation.vue'
import CubeOutline from 'vue-material-design-icons/CubeOutline.vue'
import Domain from 'vue-material-design-icons/Domain.vue'
import Earth from 'vue-material-design-icons/Earth.vue'
import EmailAlert from 'vue-material-design-icons/EmailAlert.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import EmoticonSad from 'vue-material-design-icons/EmoticonSad.vue'
import EyeOutline from 'vue-material-design-icons/EyeOutline.vue'
import Factory from 'vue-material-design-icons/Factory.vue'
import FileAlertOutline from 'vue-material-design-icons/FileAlertOutline.vue'
import FileCertificateOutline from 'vue-material-design-icons/FileCertificateOutline.vue'
import FileChartOutline from 'vue-material-design-icons/FileChartOutline.vue'
import FileCheckOutline from 'vue-material-design-icons/FileCheckOutline.vue'
import FileCompare from 'vue-material-design-icons/FileCompare.vue'
import FileDocument from 'vue-material-design-icons/FileDocument.vue'
import FileDocumentAlertOutline from 'vue-material-design-icons/FileDocumentAlertOutline.vue'
import FileDocumentCheckOutline from 'vue-material-design-icons/FileDocumentCheckOutline.vue'
import FileDocumentEditOutline from 'vue-material-design-icons/FileDocumentEditOutline.vue'
import FileDocumentMultiple from 'vue-material-design-icons/FileDocumentMultiple.vue'
import FileDocumentMultipleOutline from 'vue-material-design-icons/FileDocumentMultipleOutline.vue'
import FileDocumentOutline from 'vue-material-design-icons/FileDocumentOutline.vue'
import FileEyeOutline from 'vue-material-design-icons/FileEyeOutline.vue'
import FileSign from 'vue-material-design-icons/FileSign.vue'
import FileTreeOutline from 'vue-material-design-icons/FileTreeOutline.vue'
import FlagCheckered from 'vue-material-design-icons/FlagCheckered.vue'
import FlagOutline from 'vue-material-design-icons/FlagOutline.vue'
import FolderAccountOutline from 'vue-material-design-icons/FolderAccountOutline.vue'
import FolderCogOutline from 'vue-material-design-icons/FolderCogOutline.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import FolderTextOutline from 'vue-material-design-icons/FolderTextOutline.vue'
import FormatListBulletedType from 'vue-material-design-icons/FormatListBulletedType.vue'
import FormatListChecks from 'vue-material-design-icons/FormatListChecks.vue'
import Forum from 'vue-material-design-icons/Forum.vue'
import Gauge from 'vue-material-design-icons/Gauge.vue'
import GaugeFull from 'vue-material-design-icons/GaugeFull.vue'
import Gavel from 'vue-material-design-icons/Gavel.vue'
import GestureTapButton from 'vue-material-design-icons/GestureTapButton.vue'
import HandHeartOutline from 'vue-material-design-icons/HandHeartOutline.vue'
import HandshakeOutline from 'vue-material-design-icons/HandshakeOutline.vue'
import Headset from 'vue-material-design-icons/Headset.vue'
import History from 'vue-material-design-icons/History.vue'
import HumanMaleChild from 'vue-material-design-icons/HumanMaleChild.vue'
import HumanWheelchair from 'vue-material-design-icons/HumanWheelchair.vue'
import LayersOutline from 'vue-material-design-icons/LayersOutline.vue'
import Lightbulb from 'vue-material-design-icons/Lightbulb.vue'
import LinkVariant from 'vue-material-design-icons/LinkVariant.vue'
import ListBoxOutline from 'vue-material-design-icons/ListBoxOutline.vue'
import ListStatus from 'vue-material-design-icons/ListStatus.vue'
import MapMarker from 'vue-material-design-icons/MapMarker.vue'
import MapMarkerOutline from 'vue-material-design-icons/MapMarkerOutline.vue'
import MapMarkerPath from 'vue-material-design-icons/MapMarkerPath.vue'
import MapOutline from 'vue-material-design-icons/MapOutline.vue'
import MessageAlertOutline from 'vue-material-design-icons/MessageAlertOutline.vue'
import MessageOutline from 'vue-material-design-icons/MessageOutline.vue'
import MessageText from 'vue-material-design-icons/MessageText.vue'
import MessageTextOutline from 'vue-material-design-icons/MessageTextOutline.vue'
import NoteTextOutline from 'vue-material-design-icons/NoteTextOutline.vue'
import OfficeBuilding from 'vue-material-design-icons/OfficeBuilding.vue'
import OfficeBuildingOutline from 'vue-material-design-icons/OfficeBuildingOutline.vue'
import PaperclipCheck from 'vue-material-design-icons/PaperclipCheck.vue'
import PercentOutline from 'vue-material-design-icons/PercentOutline.vue'
import PhoneForward from 'vue-material-design-icons/PhoneForward.vue'
import PhoneInTalk from 'vue-material-design-icons/PhoneInTalk.vue'
import PhoneReturn from 'vue-material-design-icons/PhoneReturn.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ProgressClock from 'vue-material-design-icons/ProgressClock.vue'
import Receipt from 'vue-material-design-icons/Receipt.vue'
import Refresh from 'vue-material-design-icons/Refresh.vue'
import RobotOutline from 'vue-material-design-icons/RobotOutline.vue'
import RoutesClock from 'vue-material-design-icons/RoutesClock.vue'
import ScaleBalance from 'vue-material-design-icons/ScaleBalance.vue'
import ScriptText from 'vue-material-design-icons/ScriptText.vue'
import Send from 'vue-material-design-icons/Send.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import ShieldCheckOutline from 'vue-material-design-icons/ShieldCheckOutline.vue'
import ShieldKeyOutline from 'vue-material-design-icons/ShieldKeyOutline.vue'
import ShieldLockOutline from 'vue-material-design-icons/ShieldLockOutline.vue'
import SignDirection from 'vue-material-design-icons/SignDirection.vue'
import Sitemap from 'vue-material-design-icons/Sitemap.vue'
import SitemapOutline from 'vue-material-design-icons/SitemapOutline.vue'
import SourceBranch from 'vue-material-design-icons/SourceBranch.vue'
import StairsUp from 'vue-material-design-icons/StairsUp.vue'
import StoreOutline from 'vue-material-design-icons/StoreOutline.vue'
import SwapHorizontal from 'vue-material-design-icons/SwapHorizontal.vue'
import Sync from 'vue-material-design-icons/Sync.vue'
import TableColumn from 'vue-material-design-icons/TableColumn.vue'
import TableLarge from 'vue-material-design-icons/TableLarge.vue'
import TableSettings from 'vue-material-design-icons/TableSettings.vue'
import TagOutline from 'vue-material-design-icons/TagOutline.vue'
import Timeline from 'vue-material-design-icons/Timeline.vue'
import TimerOutline from 'vue-material-design-icons/TimerOutline.vue'
import TimerSandFull from 'vue-material-design-icons/TimerSandFull.vue'
import TrayFull from 'vue-material-design-icons/TrayFull.vue'
import Tune from 'vue-material-design-icons/Tune.vue'
import ViewColumnOutline from 'vue-material-design-icons/ViewColumnOutline.vue'
import ViewDashboard from 'vue-material-design-icons/ViewDashboard.vue'
import ViewDashboardOutline from 'vue-material-design-icons/ViewDashboardOutline.vue'

export default {
	Account,
	AccountArrowRight,
	AccountClock,
	AccountGroup,
	AccountGroupOutline,
	AccountHardHat,
	AccountKeyOutline,
	AccountMultipleOutline,
	AccountOutline,
	AccountPlusOutline,
	AccountSwitch,
	AccountSwitchOutline,
	AccountTieOutline,
	AccountVoice,
	Alarm,
	AlertCircleOutline,
	AlertOctagonOutline,
	AlertOutline,
	ArrowUpBoldCircle,
	BadgeAccountOutline,
	BankTransfer,
	BellCogOutline,
	BellPlusOutline,
	BellRing,
	BellRingOutline,
	BookOpenPageVariant,
	BookOpenVariantOutline,
	BriefcaseAccountOutline,
	BriefcaseOutline,
	BriefcaseVariantOutline,
	Calculator,
	Calendar,
	CalendarTextOutline,
	CameraOutline,
	Cash,
	CashMultiple,
	CashRefund,
	CashRegister,
	ChartBoxOutline,
	ChartLine,
	ChartSankey,
	CheckCircleOutline,
	CheckDecagram,
	CheckboxMarkedCircleOutline,
	CheckboxMarkedOutline,
	CheckboxOutline,
	ClipboardAccountOutline,
	ClipboardCheckMultipleOutline,
	ClipboardCheckOutline,
	ClipboardList,
	ClipboardListOutline,
	ClipboardPulseOutline,
	ClipboardSearchOutline,
	ClipboardTextOutline,
	ClockAlertOutline,
	ClockPlusOutline,
	Close,
	CloudUploadOutline,
	Cog,
	CogOutline,
	CommentOutline,
	CommentQuestionOutline,
	CommentTextOutline,
	Connection,
	Creation,
	CubeOutline,
	Domain,
	Earth,
	EmailAlert,
	EmailOutline,
	EmoticonSad,
	EyeOutline,
	Factory,
	FileAlertOutline,
	FileCertificateOutline,
	FileChartOutline,
	FileCheckOutline,
	FileCompare,
	FileDocument,
	FileDocumentAlertOutline,
	FileDocumentCheckOutline,
	FileDocumentEditOutline,
	FileDocumentMultiple,
	FileDocumentMultipleOutline,
	FileDocumentOutline,
	FileEyeOutline,
	FileSign,
	FileTreeOutline,
	FlagCheckered,
	FlagOutline,
	FolderAccountOutline,
	FolderCogOutline,
	FolderOutline,
	FolderTextOutline,
	FormatListBulletedType,
	FormatListChecks,
	Forum,
	Gauge,
	GaugeFull,
	Gavel,
	GestureTapButton,
	HandHeartOutline,
	HandshakeOutline,
	Headset,
	History,
	HumanMaleChild,
	HumanWheelchair,
	LayersOutline,
	Lightbulb,
	LinkVariant,
	ListBoxOutline,
	ListStatus,
	MapMarker,
	MapMarkerOutline,
	MapMarkerPath,
	MapOutline,
	MessageAlertOutline,
	MessageOutline,
	MessageText,
	MessageTextOutline,
	NoteTextOutline,
	OfficeBuilding,
	OfficeBuildingOutline,
	PaperclipCheck,
	PercentOutline,
	PhoneForward,
	PhoneInTalk,
	PhoneReturn,
	Plus,
	ProgressClock,
	Receipt,
	Refresh,
	RobotOutline,
	RoutesClock,
	ScaleBalance,
	ScriptText,
	Send,
	ShareVariant,
	ShieldCheckOutline,
	ShieldKeyOutline,
	ShieldLockOutline,
	SignDirection,
	Sitemap,
	SitemapOutline,
	SourceBranch,
	StairsUp,
	StoreOutline,
	SwapHorizontal,
	Sync,
	TableColumn,
	TableLarge,
	TableSettings,
	TagOutline,
	Timeline,
	TimerOutline,
	TimerSandFull,
	TrayFull,
	Tune,
	ViewColumnOutline,
	ViewDashboard,
	ViewDashboardOutline,
}
