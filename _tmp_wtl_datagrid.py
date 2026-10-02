from pathlib import Path
import re

path = Path(r"c:\Users\Zunera Kamran\Desktop\hub-finproms-frontend\src\websiteCompliance\components\WebsiteComplianceTemplatesPanel.jsx")
text = path.read_text(encoding="utf-8")

# 1) Update imports
old_imports = """import {
  FaCheckCircle,
  FaClock,
  FaCog,
  FaEdit,
  FaEyeSlash,
  FaGlobe,
  FaImage,
  FaLayerGroup,
  FaPalette,
  FaPen,
  FaPlus,
  FaRocket,
  FaSearch,
  FaSync,
  FaThLarge,
  FaTimes,
  FaTimesCircle,
  FaTrash,
  FaUpload,
} from 'react-icons/fa'
import { useHub } from '../../context/HubContext'
import ComplianceStatusText from '../../components/ComplianceStatusText'
import FileDropzone from '../../components/FileDropzone'
import RequiredMark from '../../components/RequiredMark'
import RichTextEditor from '../../components/RichTextEditor'
import { websiteComplianceAssetUrl } from '../../api/client'
import { truncateRichText } from '../../utils/richText'
import { defaultTemplatePreviewUrl, resolveHubPreviewBase } from '../utils/assetUrl'
import { sectionDisplayName } from '../utils/sectionDisplay'
import TemplateScrollPreview from './TemplateScrollPreview'
import api from '../wcApi'"""

new_imports = """import {
  FaCheckCircle,
  FaCog,
  FaEdit,
  FaEye,
  FaEyeSlash,
  FaGlobe,
  FaImage,
  FaLayerGroup,
  FaPalette,
  FaPen,
  FaPlus,
  FaRocket,
  FaSync,
  FaTimes,
  FaTrash,
  FaUpload,
} from 'react-icons/fa'
import { useHub } from '../../context/HubContext'
import DataGrid, { DataGridDate, DataGridIconBtn } from '../../components/DataGrid'
import FileDropzone from '../../components/FileDropzone'
import RequiredMark from '../../components/RequiredMark'
import RichTextEditor from '../../components/RichTextEditor'
import WcStatusBadge from '../../components/WebsiteComplianceUI'
import { websiteComplianceAssetUrl } from '../../api/client'
import { formatDateTime } from '../../utils/dateFormat'
import { truncateRichText } from '../../utils/richText'
import { defaultTemplatePreviewUrl, resolveHubPreviewBase } from '../utils/assetUrl'
import { sectionDisplayName } from '../utils/sectionDisplay'
import api from '../wcApi'"""

if old_imports not in text:
    raise SystemExit('imports block not found')
text = text.replace(old_imports, new_imports, 1)

# 2) Remove STATUS_CONFIG + StatusBadge
text, n = re.subn(
    r"const STATUS_CONFIG = \{.*?\n\}\n\nfunction StatusBadge\(\{ status \}\) \{.*?\n\}\n\n",
    "",
    text,
    count=1,
    flags=re.S,
)
if n != 1:
    raise SystemExit(f'StatusBadge removal failed ({n})')

# 3) Simplify state: drop templateSearch/requestSearch; use appliedStatus
text = text.replace(
    """  const [templateSearch, setTemplateSearch] = useState('')
  const [requestSearch, setRequestSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
""",
    """  const [statusFilter, setStatusFilter] = useState('')
  const [appliedStatus, setAppliedStatus] = useState('')
""",
)

# 4) Replace filtered memos
old_filters = """  const filteredTemplates = useMemo(() => {
    const q = templateSearch.trim().toLowerCase()
    if (!q) return templates
    return templates.filter(
      (tpl) =>
        tpl.name?.toLowerCase().includes(q) ||
        tpl.slug?.toLowerCase().includes(q) ||
        tpl.description?.toLowerCase().includes(q)
    )
  }, [templates, templateSearch])

  const filteredRequests = useMemo(() => {
    const q = requestSearch.trim().toLowerCase()
    return requests.filter((req) => {
      const matchesStatus = statusFilter === 'all' || req.status === statusFilter
      const matchesSearch =
        !q ||
        [requestRequesterName(req, ''), req.template_name, req.domain_name, req.domain]
          .filter(Boolean)
          .some((v) => String(v).toLowerCase().includes(q))
      return matchesStatus && matchesSearch
    })
  }, [requests, requestSearch, statusFilter])
"""

new_filters = """  const filteredRequests = useMemo(() => {
    if (!appliedStatus) return requests
    return requests.filter((r) => String(r.status || '').toLowerCase() === appliedStatus.toLowerCase())
  }, [requests, appliedStatus])

  const templateColumns = useMemo(
    () => [
      {
        key: 'id',
        label: '#',
        narrow: true,
        render: (row) => <strong>{row.id}</strong>,
        filterValue: (row) => String(row.id),
        sortValue: (row) => Number(row.id) || 0,
      },
      {
        key: 'name',
        label: 'Name',
        grow: true,
        render: (row) => row.name || '—',
        filterValue: (row) => row.name || '',
      },
      {
        key: 'slug',
        label: 'Slug',
        render: (row) => <span className="muted">{row.slug || '—'}</span>,
        filterValue: (row) => row.slug || '',
      },
      {
        key: 'status',
        label: 'Status',
        fit: true,
        render: (row) =>
          row.is_active ? (
            <span className="inline-flex items-center gap-1 text-xs font-bold text-emerald-700">
              <FaCheckCircle aria-hidden /> Active
            </span>
          ) : (
            <span className="inline-flex items-center gap-1 text-xs font-bold text-gray-500">
              <FaEyeSlash aria-hidden /> Disabled
            </span>
          ),
        filterValue: (row) => (row.is_active ? 'Active' : 'Disabled'),
        truncate: false,
      },
      {
        key: 'description',
        label: 'Description',
        render: (row) => truncateRichText(row.description, 80) || '—',
        filterValue: (row) => truncateRichText(row.description, 200) || '',
      },
      {
        key: 'preview',
        label: 'Preview',
        filterable: false,
        sortable: false,
        render: (row) =>
          row.preview_url ? (
            <a
              href={row.preview_url}
              target="_blank"
              rel="noreferrer"
              onClick={(e) => e.stopPropagation()}
            >
              Open
            </a>
          ) : (
            '—'
          ),
        truncate: false,
      },
    ],
    []
  )

  const deploymentColumns = useMemo(
    () => [
      {
        key: 'id',
        label: '#',
        narrow: true,
        render: (row) => <strong>#{row.id}</strong>,
        filterValue: (row) => String(row.id),
        sortValue: (row) => Number(row.id) || 0,
      },
      {
        key: 'requester',
        label: 'Requested by',
        render: (row) => requestRequesterName(row, '—'),
        filterValue: (row) => requestRequesterName(row, ''),
      },
      {
        key: 'template_name',
        label: 'Template',
        render: (row) => row.template_name || '—',
        filterValue: (row) => row.template_name || '',
      },
      {
        key: 'domain_name',
        label: 'Domain',
        grow: true,
        render: (row) => (
          <span className="inline-flex items-center gap-1.5">
            <FaGlobe aria-hidden className="text-gray-400" style={{ width: 12, height: 12 }} />
            {row.domain_name || row.domain || '—'}
          </span>
        ),
        filterValue: (row) => row.domain_name || row.domain || '',
        truncate: false,
      },
      {
        key: 'status',
        label: 'Status',
        fit: true,
        render: (row) => (
          <WcStatusBadge
            status={row.status}
            at={row.deployed_at || row.updated_at || row.created_at}
          />
        ),
        filterValue: (row) =>
          [row.status, formatDateTime(row.deployed_at || row.updated_at || row.created_at, '')]
            .filter(Boolean)
            .join(' '),
        truncate: false,
      },
      {
        key: 'created_at',
        label: 'Created',
        date: true,
        render: (row) => <DataGridDate value={row.created_at} />,
        filterValue: (row) => formatDateTime(row.created_at, ''),
        sortValue: (row) => (row.created_at ? new Date(row.created_at).getTime() : 0),
        truncate: false,
      },
      {
        key: 'live_url',
        label: 'Live URL',
        render: (row) =>
          row.cpanel_domain ? (
            <a
              href={
                row.cpanel_domain.startsWith('http')
                  ? row.cpanel_domain
                  : `https://${row.cpanel_domain}`
              }
              target="_blank"
              rel="noopener noreferrer"
              onClick={(e) => e.stopPropagation()}
            >
              {row.cpanel_domain}
            </a>
          ) : (
            '—'
          ),
        filterValue: (row) => row.cpanel_domain || '',
        truncate: false,
      },
    ],
    []
  )
"""

if old_filters not in text:
    raise SystemExit('filtered memos not found')
text = text.replace(old_filters, new_filters, 1)

# 5) Replace main UI return body (from alerts/tabs through tables) — keep modals
marker_start = "  return (\n    <div className=\"space-y-4\">"
marker_end = "      {showTemplateModal && ("

start = text.find(marker_start)
end = text.find(marker_end)
if start < 0 or end < 0:
    raise SystemExit(f'UI markers not found start={start} end={end}')

new_ui = r'''  return (
    <div>
      {error ? <div className="alert">{error}</div> : null}
      {message ? <div className="alert success">{message}</div> : null}

      <form
        className="filters-row"
        onSubmit={(e) => {
          e.preventDefault()
          if (activeTab === 'deployments') setAppliedStatus(statusFilter)
        }}
      >
        <div className="inline-flex rounded-xl border border-gray-200 bg-white p-1">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setActiveTab(tab.id)}
              className={`px-3 py-1.5 text-xs font-bold rounded-lg transition ${
                activeTab === tab.id
                  ? 'bg-[var(--brand-dark)] text-white'
                  : 'text-gray-600 hover:bg-gray-50'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>

        {activeTab === 'deployments' && canViewDeployments ? (
          <>
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
              <option value="">All statuses</option>
              <option value="pending">Pending</option>
              <option value="deployed">Deployed</option>
              <option value="rejected">Rejected</option>
            </select>
            <button className="btn primary" type="submit">
              Filter
            </button>
          </>
        ) : null}

        {activeTab === 'templates' && canManageTemplates ? (
          <button type="button" className="btn primary" onClick={openCreateTemplateModal}>
            <FaPlus aria-hidden style={{ marginRight: 6 }} />
            Register template
          </button>
        ) : null}

        <button
          type="button"
          className="btn ghost"
          onClick={() => fetchData(true)}
          disabled={loading}
        >
          <FaSync aria-hidden style={{ marginRight: 6 }} />
          Refresh
        </button>
      </form>

      {activeTab === 'templates' && canManageTemplates ? (
        <DataGrid
          columns={templateColumns}
          rows={templates}
          loading={loading}
          pageSize={10}
          emptyMessage="No templates yet. Register a template to make it available for deployment requests."
          actionsLabel="Actions"
          actions={(row) => (
            <>
              <DataGridIconBtn
                icon={FaEdit}
                label="Edit template"
                variant="primary"
                onClick={() => openEditTemplateModal(row)}
              />
              <DataGridIconBtn
                icon={FaTrash}
                label="Delete template"
                onClick={() => handleDeleteTemplate(row)}
              />
            </>
          )}
        />
      ) : null}

      {activeTab === 'deployments' && canViewDeployments ? (
        <DataGrid
          columns={deploymentColumns}
          rows={filteredRequests}
          loading={loading}
          pageSize={10}
          emptyMessage={
            canDeployWebsites
              ? 'No deployment requests yet.'
              : 'No deployment requests in this queue.'
          }
          actionsLabel="Actions"
          actions={(row) => (
            <>
              {row.status === 'deployed' && canPublishLive ? (
                <DataGridIconBtn
                  icon={FaPen}
                  label="Edit & publish"
                  variant="primary"
                  as={Link}
                  to={`/my-dashboard/website-compliance/publish/${row.id}`}
                />
              ) : null}
              {row.status === 'deployed' && canManageSections ? (
                <DataGridIconBtn
                  icon={FaLayerGroup}
                  label="Manage sections"
                  onClick={() => openSectionManageModal(row)}
                />
              ) : null}
              {canDeployWebsites && row.status === 'deployed' ? (
                <DataGridIconBtn
                  icon={FaPalette}
                  label="Update branding"
                  onClick={() => openBrandingModal(row)}
                />
              ) : null}
              {canDeployWebsites ? (
                <DataGridIconBtn
                  icon={row.status === 'deployed' ? FaCog : FaRocket}
                  label={row.status === 'deployed' ? 'Update deployment' : 'Deploy to cPanel'}
                  variant="primary"
                  onClick={() => openDeployModal(row)}
                />
              ) : null}
              {!canDeployWebsites &&
              !(row.status === 'deployed' && (canPublishLive || canManageSections)) ? (
                <span className="muted">—</span>
              ) : null}
            </>
          )}
        />
      ) : null}

      '''

text = text[:start] + new_ui + text[end:]

# Remove unused TemplateScrollPreview if no longer referenced
if 'TemplateScrollPreview' not in text:
    pass  # already removed from imports
elif 'TemplateScrollPreview' in text and text.count('TemplateScrollPreview') == 0:
    pass

# FaEye unused? keep for potential preview - actually unused, remove from imports if present
# FaThLarge removed already

path.write_text(text, encoding='utf-8')
print('WebsiteComplianceTemplatesPanel updated')
# sanity: leftover old search vars?
for bad in ['templateSearch', 'requestSearch', 'filteredTemplates', 'StatusBadge', 'TemplateScrollPreview', 'STATUS_CONFIG']:
    if bad in text:
        print('WARNING still contains', bad)
    else:
        print('ok removed', bad)
