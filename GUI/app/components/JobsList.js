/* eslint-disable promise/always-return,promise/catch-or-return */
// @flow
import React, { Component } from 'react';
import { withStyles } from '@material-ui/core/styles';
import { Typography, Paper, Box, Icon } from '@material-ui/core';
import LinearProgress from '@material-ui/core/LinearProgress';
import CircularProgress from '@material-ui/core/CircularProgress';
import { ConnectTable } from './UI/PaginatedRemoteTable';
import * as JobsActions from '../actions/jobs';
import type { StateType } from '../reducers/types';
import IconButton from './UI/IconButton';
import LogsDialog from './UI/LogsDialog';
import * as Api from '../api';

const JobsTable = ConnectTable(
  (state: StateType) => ({
    paginationState: state.jobs.jobsList.state,
    pagesCollection: state.jobs.jobsList.pages
  }),
  {
    changeRowsPerPage: JobsActions.setPerPage,
    requestPage: JobsActions.requestPage
  }
);

const style = theme => ({
  root: {
    padding: theme.spacing(3, 2)
  },
  loading: {
    width: '100%',
    '& > * + *': {
      marginTop: theme.spacing(2)
    }
  }
});

type Props = {
  deletingJobs: number[],
  submittingJobs: number[],
  submitJob: (number, ?number) => void,
  refreshPage: number => void,
  deleteJob: number => void,
  pushNotification: (string, 'success' | 'warning' | 'error' | 'info') => void,
  classes: {
    root: *,
    loading: *
  }
};

type State = {
  isLoading: boolean,
  logsOpen: boolean,
  logsSelectedJobId: ?number,
  currentPage: ?number,
  downloading: number[]
};

class JobsList extends Component<Props, State> {
  constructor(props) {
    super(props);
    this.state = {
      isLoading: false,
      logsOpen: false,
      logsSelectedJobId: null,
      currentPage: null,
      downloading: []
    };
  }

  handleLogsClose = () => {
    const { currentPage } = this.state;
    const { refreshPage } = this.props;
    this.setState({ logsOpen: false, logsSelectedJobId: null });
    if (currentPage) refreshPage(currentPage);
  };

  handleJobDelete = (jobId: number) => {
    const { deleteJob } = this.props;
    deleteJob(jobId);
  };

  openResultsFolder = (jobId: number) => {
    const { pushNotification } = this.props;
    Api.Jobs.openLocalFolder(jobId).catch(e =>
      pushNotification(`An error occurred ${e.message}`, 'error')
    );
  };

  downloadResults = (jobId: number) => {
    const { pushNotification } = this.props;
    Api.Jobs.download(
      jobId,
      () => {
        const { downloading } = this.state;
        this.setState({
          downloading: [...downloading, jobId]
        });
      },
      () => {
        const { downloading } = this.state;
        this.setState({
          downloading: downloading.filter(i => i !== jobId)
        });
      }
    ).catch(e => pushNotification(`An error occurred ${e.message}`, 'error'));
  };

  handleLogsSelectJob = (jobId: number) =>
    this.setState({
      logsOpen: true,
      logsSelectedJobId: jobId
    });

  handlePageChange = (currentPage: number) => this.setState({ currentPage });

  render() {
    const { deletingJobs, submittingJobs, submitJob, classes } = this.props;
    const {
      isLoading,
      logsOpen,
      logsSelectedJobId,
      currentPage,
      downloading
    } = this.state;
    return (
      <>
        <Box>
          <Paper className={classes.root}>
            <Typography variant="h5" component="h3">
              Jobs list
            </Typography>
            <Typography component="p" />
            {isLoading && (
              <div className={classes.loading}>
                <LinearProgress />
              </div>
            )}
            <div>
              <JobsTable
                onPageChange={this.handlePageChange}
                columns={[
                  {
                    id: 'name',
                    label: 'Name'
                  },
                  {
                    id: 'readable_type',
                    label: 'Type'
                  },
                  {
                    id: 'status',
                    label: 'Status',
                    format: row => {
                      if (deletingJobs.includes(row.id)) return 'Deleting';
                      return Api.Utils.capitalize(row.status);
                    }
                  },
                  {
                    id: 'created_at_diff',
                    label: 'Created at'
                  },
                  {
                    id: 'id',
                    align: 'center',
                    label: 'Action',
                    format: row => {
                      const components = [];
                      if (!deletingJobs.includes(row.id)) {
                        if (row.status === 'ready') {
                          if (submittingJobs.includes(row.id)) {
                            components.push(
                              <CircularProgress
                                key={`${row.id}-submitting`}
                                size={20}
                              />
                            );
                          } else {
                            components.push(
                              <IconButton
                                title="Submit"
                                color="primary"
                                onClick={() => submitJob(row.id, currentPage)}
                                key={`${row.id}-submit`}
                              >
                                <Icon className="fas fa-play" />
                              </IconButton>
                            );
                          }
                        }
                        if (row.status !== 'ready' && row.status !== 'queued') {
                          components.push(
                            <IconButton
                              title="Logs"
                              onClick={() => this.handleLogsSelectJob(row.id)}
                              key={`${row.id}-logs`}
                            >
                              <Icon className="fas fa-file-alt" />
                            </IconButton>
                          );
                        }
                        if (row.status === 'completed') {
                          if (downloading.includes(row.id)) {
                            components.push(
                              <CircularProgress
                                key={`${row.id}-downloading`}
                                size={20}
                              />
                            );
                          } else {
                            components.push(
                              <IconButton
                                title="Save results"
                                onClick={() => this.downloadResults(row.id)}
                                key={`${row.id}-save`}
                              >
                                <Icon className="fas fa-save" />
                              </IconButton>
                            );
                          }
                          if (Api.Settings.isLocal()) {
                            components.push(
                              <IconButton
                                title="Open results folder"
                                onClick={() => this.openResultsFolder(row.id)}
                                key={`${row.id}-open-folder`}
                              >
                                <Icon className="fas fa-folder-open" />
                              </IconButton>
                            );
                          }
                        }
                        if (row.status !== 'processing') {
                          components.push(
                            <IconButton
                              title="Delete"
                              color="secondary"
                              onClick={() => this.handleJobDelete(row.id)}
                              key={`${row.id}-delete`}
                            >
                              <Icon className="fas fa-trash" />
                            </IconButton>
                          );
                        }
                      }
                      return <>{components}</>;
                    }
                  }
                ]}
              />
            </div>
          </Paper>
        </Box>
        <LogsDialog
          jobId={logsSelectedJobId}
          open={logsOpen}
          onClose={this.handleLogsClose}
        />
      </>
    );
  }
}

export default withStyles(style)(JobsList);
