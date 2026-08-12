import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { RequireAuth } from './adapter/ui/components/RequireAuth'
import { FleetPage } from './adapter/ui/pages/FleetPage'
import { LoginPage } from './adapter/ui/pages/LoginPage'
import { ProjectPage } from './adapter/ui/pages/ProjectPage'

export default function App() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route element={<RequireAuth />}>
          <Route path="/" element={<FleetPage />} />
          <Route path="/projects/:gameId" element={<ProjectPage />} />
        </Route>
        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
